<?php

namespace App\Services;

use App\Data\InvoiceData;
use App\Jobs\AuthorizeSriVoucherJob;
use App\Models\Appointment;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Workspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Business logic for the billing module.
 *
 * Covers invoice creation, sequential numbering with pessimistic locking,
 * total/status recalculation after payments, and cancellation/voiding.
 */
class InvoiceService
{
    /**
     * Generates the next invoice number for the workspace.
     *
     * Uses SELECT FOR UPDATE to avoid duplicates under concurrency.
     * Increments invoice_counter on the workspace row and returns
     * the formatted code, e.g. "F-0001".
     */
    public function nextNumber(Workspace $workspace): string
    {
        return DB::transaction(function () use ($workspace) {
            /** @var Workspace $locked */
            $locked = Workspace::lockForUpdate()->findOrFail($workspace->id);

            $counter = ($locked->invoice_counter ?? 0) + 1;

            $locked->update(['invoice_counter' => $counter]);

            return 'F-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Creates an invoice with its items in a single transaction.
     *
     * Accepts a typed InvoiceData (preferred contract, used by the API via
     * StoreInvoiceRequest) or the equivalent snake_case array (used by Filament
     * and existing callers). The input is normalized to InvoiceData and the
     * proven bcmath internals continue to consume the array shape.
     *
     * @throws \InvalidArgumentException when items is empty.
     */
    public function create(InvoiceData|array $data): Invoice
    {
        $dto = $data instanceof InvoiceData ? $data : InvoiceData::fromArray($data);

        if (empty($dto->items)) {
            throw new \InvalidArgumentException('La factura debe tener al menos un ítem.');
        }

        $data = $dto->toArray();

        return DB::transaction(function () use ($data) {
            $workspace = Workspace::findOrFail($data['workspace_id']);

            $this->assertBillableAppointment($data);

            $subtotal   = '0.00';
            $ivaTotal   = '0.00';
            $itemsReady = [];

            foreach ($data['items'] as $index => $item) {
                $quantity           = (string) $item['quantity'];
                $unitPrice          = (string) $item['unit_price'];
                $ivaRate            = (string) ($item['iva_rate'] ?? '0.00');
                $discountPercentage = (string) ($item['discount_percentage'] ?? '0');

                // Gross base (quantity × unit price)
                $lineBase = bcmul($quantity, $unitPrice, 10);

                // Item discount
                $discountAmount = bcmul($lineBase, bcdiv($discountPercentage, '100', 10), 10);
                $lineBaseAfterDiscount = bcsub($lineBase, $discountAmount, 10);

                // IVA is applied on the discounted base
                $ivaAmount = bcmul($lineBaseAfterDiscount, bcdiv($ivaRate, '100', 10), 10);
                $lineTotal = bcadd($lineBaseAfterDiscount, $ivaAmount, 10);

                $subtotal = bcadd($subtotal, $lineBaseAfterDiscount, 10);
                $ivaTotal = bcadd($ivaTotal, $ivaAmount, 10);

                $itemsReady[] = [
                    'medical_service_id' => $item['medical_service_id'] ?? null,
                    'description'        => $item['description'],
                    'quantity'           => $quantity,
                    'unit_price'         => $unitPrice,
                    'discount_percentage' => bcadd($discountPercentage, '0', 2),
                    'discount_amount'    => bcadd($discountAmount, '0', 2),
                    'iva_rate'           => $ivaRate,
                    'iva_amount'         => bcadd($ivaAmount, '0', 2),
                    'line_total'         => bcadd($lineTotal, '0', 2),
                    'sort_order'         => $item['sort_order'] ?? $index,
                ];
            }

            // Invoice-level global discount (e.g. courtesy)
            $globalDiscount = (string) ($data['discount_amount'] ?? '0');
            $subtotalAfterGlobalDiscount = bcsub($subtotal, $globalDiscount, 10);

            $total = bcadd($subtotalAfterGlobalDiscount, $ivaTotal, 2);

            $invoice = Invoice::create([
                'workspace_id'   => $data['workspace_id'],
                'patient_id'     => $data['patient_id'],
                'appointment_id' => $data['appointment_id'] ?? null,
                'invoice_number' => $this->nextNumber($workspace),
                'issued_at'      => $data['issued_at'],
                'status'         => 'issued',
                'subtotal'       => bcadd($subtotalAfterGlobalDiscount, '0', 2),
                'iva_amount'     => bcadd($ivaTotal, '0', 2),
                'discount_amount' => bcadd($globalDiscount, '0', 2),
                'total'          => $total,
                'amount_paid'    => '0.00',
                'notes'          => $data['notes'] ?? null,
                'created_by'         => $data['created_by'] ?? null,
                'attending_doctor_id' => $data['attending_doctor_id'] ?? null,
                'attending_doctor_commission_pct' => $data['attending_doctor_commission_pct'] ?? null,
                'attending_doctor_commission_amount' => $data['attending_doctor_commission_amount'] ?? null,
                'issued_by_user_id'  => $data['issued_by_user_id'] ?? null,
                // Buyer (comprador) fiscal data for the electronic voucher
                'buyer_id_type'  => $data['buyer_id_type'] ?? '07',
                'buyer_id'       => $data['buyer_id'] ?? null,
                'buyer_name'     => $data['buyer_name'] ?? null,
                'buyer_address'  => $data['buyer_address'] ?? null,
                'buyer_email'    => $data['buyer_email'] ?? null,
            ]);

            foreach ($itemsReady as $item) {
                InvoiceItem::create(array_merge(['invoice_id' => $invoice->id], $item));
            }

            $invoice = $invoice->fresh();

            // Dispatch SRI authorization after the transaction commits so the
            // job never runs against an uncommitted invoice.
            if ($workspace->canIssueSriVouchers()) {
                AuthorizeSriVoucherJob::dispatch($invoice)->afterCommit();
            }

            return $invoice;
        });
    }

    /**
     * Locks an appointment before it becomes an invoice. The existing billables
     * queue is built from completed appointments without an invoice, but the
     * write boundary must repeat that rule: two cashiers (or a crafted request)
     * cannot be allowed to bypass a read-time list.
     *
     * @param array<string, mixed> $data
     */
    private function assertBillableAppointment(array $data): void
    {
        if (empty($data['appointment_id'])) {
            return;
        }

        /** @var Appointment $appointment */
        $appointment = Appointment::query()
            ->lockForUpdate()
            ->findOrFail((int) $data['appointment_id']);

        if ($appointment->status !== 'completed') {
            throw ValidationException::withMessages([
                'appointment_id' => 'Solo se puede facturar una atención completada.',
            ]);
        }

        if ((int) $appointment->patient_id !== (int) $data['patient_id']) {
            throw ValidationException::withMessages([
                'appointment_id' => 'La atención seleccionada no corresponde a este paciente.',
            ]);
        }

        if (Invoice::query()->where('appointment_id', $appointment->id)->exists()) {
            throw ValidationException::withMessages([
                'appointment_id' => 'Esta atención ya tiene una factura emitida.',
            ]);
        }
    }

    /**
     * Recalculates amount_paid and the invoice status from its payments.
     *
     * status:
     *   - 'paid'    → amount_paid >= total
     *   - 'partial' → amount_paid > 0
     *   - 'issued'  → no payments
     *
     * Does not fire events or re-run observers, to avoid cycles.
     */
    public function recalculate(Invoice $invoice): void
    {
        $amountPaid = (string) ($invoice->payments()->sum('amount') ?? '0.00');

        $status = match (true) {
            bccomp($amountPaid, (string) $invoice->total, 2) >= 0 => 'paid',
            bccomp($amountPaid, '0.00', 2) > 0                    => 'partial',
            default                                                 => 'issued',
        };

        // withoutEvents so observers are not triggered during recalculation
        Invoice::withoutEvents(function () use ($invoice, $amountPaid, $status) {
            $invoice->update([
                'amount_paid' => $amountPaid,
                'status'      => $status,
            ]);
        });
    }

    /**
     * Cancels an invoice that has no registered payments.
     *
     * @throws ValidationException when the invoice already has payments.
     */
    public function cancel(Invoice $invoice, string $reason): void
    {
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'No se puede cancelar una factura con pagos registrados.',
            ]);
        }

        $invoice->update([
            'status'               => 'cancelled',
            'cancelled_at'         => now(),
            'cancellation_reason'  => $reason,
        ]);
    }

    /**
     * Voids an invoice (administrative action) and automatically generates
     * a credit note when the invoice has registered payments.
     *
     * User role validation is handled at the Filament layer.
     */
    public function void(Invoice $invoice, string $reason): void
    {
        DB::transaction(function () use ($invoice, $reason): void {
            $invoice->update([
                'status'              => 'void',
                'voided_at'           => now(),
                'cancellation_reason' => $reason,
            ]);

            // If amount_paid > 0, issue a credit note automatically
            if (bccomp((string) $invoice->amount_paid, '0.00', 2) > 0) {
                $this->generateCreditNote($invoice, $reason);
            }
        });
    }

    /**
     * Generates a sequential credit note for the invoice's workspace.
     *
     * Uses SELECT FOR UPDATE (same as nextNumber) to avoid duplicates
     * under concurrency.
     */
    public function generateCreditNote(Invoice $invoice, string $reason): CreditNote
    {
        return DB::transaction(function () use ($invoice, $reason): CreditNote {
            $locked  = Workspace::lockForUpdate()->findOrFail($invoice->workspace_id);
            $counter = ($locked->credit_note_counter ?? 0) + 1;
            $locked->update(['credit_note_counter' => $counter]);

            $number = 'NC-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT);

            $creditNote = CreditNote::create([
                'workspace_id'      => $invoice->workspace_id,
                'invoice_id'        => $invoice->id,
                'credit_note_number' => $number,
                'issued_at'         => today(),
                'reason'            => $reason,
                'amount'            => $invoice->amount_paid,
                'status'            => 'issued',
                'created_by'        => $invoice->created_by,
            ]);

            if ($locked->canIssueSriVouchers()) {
                AuthorizeSriVoucherJob::dispatch($creditNote)->afterCommit();
            }

            return $creditNote;
        });
    }

    /**
     * Generates the downloadable invoice PDF.
     *
     * Loads the required relations (workspace, patient, creator, items, payments),
     * reuses the same letterhead system as the medical prescription
     * (prescription_settings + base64 logo) and renders the
     * resources/views/invoices/invoice.blade.php view with DomPDF.
     */
    public function pdf(Invoice $invoice): DomPDF
    {
        $invoice->loadMissing(['workspace', 'patient', 'creator', 'items', 'payments']);

        $workspace = $invoice->workspace;
        $settings  = $workspace?->prescription_settings ?? [];

        // RIDE: include the SRI access-key QR once the voucher is authorized.
        $sriQr = null;
        if ($invoice->isSriAuthorized() && $invoice->sri_clave_acceso) {
            $sriQr = app(\App\Services\Sri\SriService::class)->qrDataUri($invoice->sri_clave_acceso);
        }

        return Pdf::loadView('invoices.invoice', [
            'invoice'   => $invoice,
            'workspace' => $workspace,
            'patient'   => $invoice->patient,
            'creator'   => $invoice->creator,
            'settings'  => $settings,
            'logo'      => $this->logoDataUri($workspace?->logo_path),
            'sriQr'     => $sriQr,
        ])->setPaper('a4');
    }

    /**
     * Converts a private disk path into a base64 data-URI ready for <img>.
     * Returns null when the path is null or the file does not exist.
     *
     * dompdf cannot read a signed URL, so the PDF pipeline reads the bytes off
     * the disk directly — moving letterhead art to the private disk does not
     * affect it beyond the disk name.
     */
    private function logoDataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $full = $disk->path($path);
        $mime = @mime_content_type($full) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
    }
}
