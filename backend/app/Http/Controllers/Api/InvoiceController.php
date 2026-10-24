<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Invoice;
use App\Models\Patient;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\Reports\DoctorSettlementService;
use App\Services\Sri\SriService;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invoice access and lifecycle for the active workspace (WorkspaceScope isolated).
 * All money flows through the existing InvoiceService / PaymentService — this
 * controller never recomputes totals or numbering.
 */
class InvoiceController extends Controller
{
    /**
     * SRI states the list can be narrowed to. `not_sent` is stored as NULL (the
     * invoice never went to the SRI, e.g. e-invoicing is off), which is why it
     * cannot simply ride along in a whereIn.
     */
    private const SRI_STATES = ['pending', 'rejected', 'authorized', 'not_sent'];

    /**
     * `needs_attention` is not a stored value: it is the question the user
     * actually asks ("do I have anything stuck with the SRI?"). A voucher still
     * in the queue and one the SRI turned down are both unfinished business.
     */
    private const SRI_NEEDS_ATTENTION = ['pending', 'rejected'];

    /** Invoice statuses that take a voucher out of play (Invoice::isCancelled). */
    private const ANNULLED_STATUSES = ['void', 'cancelled'];

    public function index(
        Request $request,
        WorkspacePermissionService $permissions,
        ActiveWorkspace $activeWorkspace,
        DoctorSettlementService $settlement,
    ): AnonymousResourceCollection {
        // An employed doctor only sees invoices for THEIR OWN attentions — a
        // colleague's billing is not theirs to read.
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        $query = Invoice::query()
            ->with(['patient', 'attendingDoctor'])
            ->latest('issued_at')
            ->latest('id');

        if ($ownDoctorId) {
            $query->where('attending_doctor_id', $ownDoctorId)
                ->withSum('payments as collected', 'amount')
                ->with('items');
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($sriStatus = $request->query('sri_status')) {
            $this->applySriStateFilter($query, (string) $sriStatus);
        }

        $invoices = $query->paginate($request->integer('per_page', 20));

        // Decorate each invoice with what this doctor actually earns from it
        // (same math as the settlement report, computed on money collected).
        if ($ownDoctorId) {
            $workspaceId = (int) $activeWorkspace->id();
            $rules = $settlement->rulesForDoctor($workspaceId, $ownDoctorId);
            $globalPct = $settlement->globalPctForDoctor($workspaceId, $ownDoctorId);

            $invoices->getCollection()->each(function (Invoice $invoice) use ($settlement, $rules, $globalPct): void {
                $invoice->doctor_share = $settlement->shareForInvoice($invoice, $rules, $globalPct);
            });
        }

        return InvoiceResource::collection($invoices);
    }

    /**
     * How many vouchers sit in each SRI state, for the control panel inside
     * Facturación. Exists so the client can answer "do I have anything pending?"
     * without paging through every invoice just to count them.
     *
     * Deliberately NOT a plain count per state — the collection status and the
     * calendar both come into it. Each bucket below carries the why.
     */
    public function sriSummary(
        WorkspacePermissionService $permissions,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        // An employed doctor counts only their own attentions, exactly as the
        // list does — otherwise the badge would betray a colleague's volume.
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        $base = fn (): Builder => Invoice::query()
            ->when($ownDoctorId, fn (Builder $q): Builder => $q->where('attending_doctor_id', $ownDoctorId));

        $counts = $base()
            ->selectRaw("COALESCE(sri_status, 'not_sent') AS state, COUNT(*) AS total")
            ->groupByRaw("COALESCE(sri_status, 'not_sent')")
            ->pluck('total', 'state');

        // An annulled invoice is not outstanding business: resend() refuses to
        // re-arm it, so counting it under "en cola" or "rechazadas" would show
        // work nobody can clear — a badge that never goes to zero.
        //
        // `authorized` deliberately keeps counting them: a comprobante the SRI
        // accepted is a fiscal fact whether or not the invoice was voided later
        // (that is what the credit note is for).
        $stuck = $base()
            ->whereIn('status', self::ANNULLED_STATUSES)
            ->whereIn('sri_status', self::SRI_NEEDS_ATTENTION)
            ->selectRaw('sri_status AS state, COUNT(*) AS total')
            ->groupBy('sri_status')
            ->pluck('total', 'state');

        // The reassuring number ("it works"), bounded to the month so it cannot
        // grow into the biggest figure on the panel and the only one that never
        // asks for anything. The month is the period the doctor and their
        // accountant already think in, in the app's timezone (America/Guayaquil).
        //
        // Keyed off the date the SRI answered, not the date the invoice was
        // issued: "autorizadas este mes" is about the authority's replies. A
        // voucher marked authorized without that date (older data) stays out.
        $authorizedThisMonth = $base()
            ->where('sri_status', 'authorized')
            ->where('sri_authorization_date', '>=', Carbon::today()->startOfMonth())
            ->count();

        return response()->json(
            collect(self::SRI_STATES)
                ->mapWithKeys(fn (string $state): array => [
                    $state => (int) ($counts[$state] ?? 0) - (int) ($stuck[$state] ?? 0),
                ])
                ->put('authorized_this_month', $authorizedThisMonth)
                ->all()
        );
    }

    public function show(
        int $id,
        WorkspacePermissionService $permissions,
        ActiveWorkspace $activeWorkspace,
        DoctorSettlementService $settlement,
    ): InvoiceResource {
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        $query = Invoice::with(['patient', 'items', 'payments', 'attendingDoctor', 'issuedBy']);

        // A foreign invoice must 404 for an employed doctor, not just be hidden
        // from the list.
        if ($ownDoctorId) {
            $query->where('attending_doctor_id', $ownDoctorId)
                ->withSum('payments as collected', 'amount');
        }

        $invoice = $query->findOrFail($id);

        if ($ownDoctorId) {
            $workspaceId = (int) $activeWorkspace->id();
            $invoice->doctor_share = $settlement->shareForInvoice(
                $invoice,
                $settlement->rulesForDoctor($workspaceId, $ownDoctorId),
                $settlement->globalPctForDoctor($workspaceId, $ownDoctorId),
            );
        }

        return InvoiceResource::make($invoice);
    }

    /**
     * Create an invoice via InvoiceService. workspace_id and created_by are
     * injected server-side (never trusted from the client); the patient is
     * resolved through the workspace-scoped query so foreign ids 404.
     */
    public function store(
        StoreInvoiceRequest $request,
        InvoiceService $invoiceService,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        $data = $request->validated();

        Patient::findOrFail($data['patient_id']);

        // From a completed appointment: auto-attribute the attending physician.
        if (! empty($data['appointment_id'])) {
            $appointment = Appointment::findOrFail($data['appointment_id']); // scoped 404
            $data['attending_doctor_id'] ??= $appointment->doctor_id;
        }

        // A client-supplied attending doctor must belong to the active workspace.
        if (! empty($data['attending_doctor_id'])) {
            $this->assertDoctorInWorkspace((int) $data['attending_doctor_id'], $activeWorkspace);
        } else {
            // A commission share is meaningless without an attending doctor.
            unset($data['attending_doctor_commission_pct'], $data['attending_doctor_commission_amount']);
        }

        // Trusted server-side values override anything sent by the client.
        $data['workspace_id'] = $activeWorkspace->id();
        $data['created_by'] = Auth::user()->doctor?->id;     // legacy doctor author
        $data['issued_by_user_id'] = Auth::id();                    // who keyed it (covers cashier staff)

        $invoice = $invoiceService->create($data);

        return InvoiceResource::make($invoice->load(['patient', 'items', 'payments', 'attendingDoctor', 'issuedBy']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Void an invoice (administrative). A credit note is generated automatically
     * by the service when the invoice has registered payments.
     */
    public function void(Request $request, int $id, InvoiceService $invoiceService): InvoiceResource
    {
        $invoice = Invoice::findOrFail($id);

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $invoiceService->void($invoice, $reason);

        return InvoiceResource::make($invoice->fresh()->load(['patient', 'items', 'payments', 'attendingDoctor', 'issuedBy']));
    }

    /**
     * Cancel an invoice that has no payments.
     */
    public function cancel(Request $request, int $id, InvoiceService $invoiceService): InvoiceResource
    {
        $invoice = Invoice::findOrFail($id);

        $reason = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ])['reason'];

        $invoiceService->cancel($invoice, $reason);

        return InvoiceResource::make($invoice->fresh()->load(['patient', 'items', 'payments', 'attendingDoctor', 'issuedBy']));
    }

    /**
     * Re-queue a rejected (or stuck) invoice for SRI authorization.
     *
     * SriService::resend() refuses two kinds of voucher, and the emitter has to
     * be told WHICH one they hit: an already-authorized comprobante (idempotence
     * — a double click cannot file the same one twice) and an annulled invoice
     * (filing it would present the tax authority with a document this workspace
     * considers void). Telling someone their voided invoice "was already
     * authorized" sends them hunting for an authorization that does not exist.
     */
    public function retrySri(int $id, SriService $sri): InvoiceResource
    {
        $invoice = Invoice::findOrFail($id);

        // Checked here, before resend(), only so the message can name the real
        // reason. The rule itself lives in SriService::resend(), which is what
        // the `sri:resend` sweep goes through too.
        if ($invoice->isCancelled()) {
            throw ValidationException::withMessages([
                'sri' => 'Esta factura está anulada; no se puede reenviar al SRI.',
            ]);
        }

        if (! $sri->resend($invoice)) {
            throw ValidationException::withMessages([
                'sri' => 'Este comprobante ya fue autorizado por el SRI; no se puede reenviar.',
            ]);
        }

        $invoice->forceFill(['sri_error' => null])->save();

        return InvoiceResource::make($invoice->fresh()->load(['patient', 'items', 'payments', 'attendingDoctor', 'issuedBy']));
    }

    /**
     * Stream the invoice PDF / RIDE (Spanish, patient-facing).
     */
    public function pdf(int $id, InvoiceService $invoiceService): Response
    {
        $invoice = Invoice::findOrFail($id);

        return $invoiceService->pdf($invoice)->download("{$invoice->invoice_number}.pdf");
    }

    /**
     * Register a payment against an invoice via PaymentService.
     */
    public function storePayment(
        StorePaymentRequest $request,
        int $id,
        PaymentService $paymentService,
    ): JsonResponse {
        $invoice = Invoice::findOrFail($id);

        $data = $request->validated();
        $data['registered_by'] = Auth::user()->doctor?->id; // legacy doctor column
        $data['registered_by_user_id'] = Auth::id();                // who took the money (cashier staff)

        $payment = $paymentService->register($invoice, $data);

        return PaymentResource::make($payment->load('registeredByUser'))->response()->setStatusCode(201);
    }

    /**
     * Narrow a list to one or more SRI states. Accepts a comma-separated list
     * plus the derived `needs_attention`.
     *
     * Unknown values are dropped, leaving the list unfiltered. Applying them
     * literally would answer "nothing here", which reads as "you have nothing
     * outstanding" — the most dangerous wrong answer this endpoint can give.
     */
    private function applySriStateFilter(Builder $query, string $value): void
    {
        $needsAttention = $value === 'needs_attention';

        $requested = $needsAttention
            ? self::SRI_NEEDS_ATTENTION
            : array_intersect(array_map('trim', explode(',', $value)), self::SRI_STATES);

        if ($requested === []) {
            return;
        }

        // "Necesitan atención" answers "what must I clear?", and an annulled
        // invoice can no longer be cleared — resend() refuses it. Keep it out of
        // the list so it matches the counter, which excludes it too. An explicit
        // state filter still shows them: there the user asked for that state.
        if ($needsAttention) {
            $query->whereNotIn('status', self::ANNULLED_STATUSES);
        }

        $stored = array_values(array_diff($requested, ['not_sent']));
        $wantsUnsent = in_array('not_sent', $requested, true);

        $query->where(function (Builder $q) use ($stored, $wantsUnsent): void {
            if ($stored !== []) {
                $q->whereIn('sri_status', $stored);
            }

            if ($wantsUnsent) {
                $q->orWhereNull('sri_status');
            }
        });
    }

    /**
     * Ensure a doctor belongs to the active workspace (owner or member). Doctor
     * has no WorkspaceScope, so an unvalidated id could attribute an invoice to a
     * physician outside this tenant. Mirrors AppointmentController::resolveDoctorId.
     */
    private function assertDoctorInWorkspace(int $doctorId, ActiveWorkspace $activeWorkspace): void
    {
        $workspaceId = $activeWorkspace->id();

        $belongs = Doctor::query()
            ->whereKey($doctorId)
            ->where(function ($q) use ($workspaceId): void {
                $q->where('workspace_id', $workspaceId)
                    ->orWhereHas('clinics', fn ($c) => $c->whereKey($workspaceId));
            })
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'attending_doctor_id' => 'El médico seleccionado no pertenece a este espacio de trabajo.',
            ]);
        }
    }
}
