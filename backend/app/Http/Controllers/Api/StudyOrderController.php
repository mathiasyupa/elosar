<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStudyOrderRequest;
use App\Http\Resources\StudyOrderResource;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Study;
use App\Models\StudyOrder;
use App\Services\StudyOrderService;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lab / imaging orders ("solicitud de exámenes").
 *
 * An order belongs to a patient. The consultation is the context it was asked
 * in, not a requirement for asking: a repeat lab slip requested by phone, or a
 * study ordered ahead of the appointment it will serve, has no visit behind it,
 * and forcing one used to leave an empty consultation in the chart for good.
 * When there is no consultation, `clinical_info` carries the justification —
 * which is what the PDF prints and what a laboratory and an insurer ask for.
 *
 * Isolation is handled by WorkspaceScope (bound by ResolveActiveWorkspace): the
 * parent record, the parent patient and the orders themselves resolve to 404
 * outside the active workspace. patient_id / workspace_id / created_by are
 * injected server-side from that scoped parent — never taken from the client.
 * Order items are persisted through the scoped order so they inherit its tenant
 * isolation.
 */
class StudyOrderController extends Controller
{
    /** List the orders attached to a (scoped) consultation, optional ?type filter. */
    public function index(Request $request, int $recordId): AnonymousResourceCollection
    {
        MedicalRecord::findOrFail($recordId); // 404 outside the active workspace

        $query = StudyOrder::query()
            ->where('medical_record_id', $recordId)
            ->with('items')
            ->latest('id');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return StudyOrderResource::collection($query->get());
    }

    /**
     * Every order the patient has, newest first, with or without a consultation.
     *
     * The per-consultation listing above answers "what did I ask for in this
     * visit?". This one answers the question the doctor actually has in front of
     * the patient — "what is pending for her?" — which no listing could answer
     * while an order was reachable only through the visit that produced it.
     */
    public function indexForPatient(Request $request, int $patientId): AnonymousResourceCollection
    {
        Patient::findOrFail($patientId); // 404 outside the active workspace

        $query = StudyOrder::query()
            ->where('patient_id', $patientId)
            ->with(['items', 'medicalRecord'])
            ->latest('id');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        return StudyOrderResource::collection($query->get());
    }

    /** Create a lab/imaging order against a scoped consultation. */
    public function store(StoreStudyOrderRequest $request, int $recordId): JsonResponse
    {
        $record = MedicalRecord::findOrFail($recordId);

        return $this->persist($record->patient_id, $record, $request->validated());
    }

    /**
     * Create an order for a patient, with the consultation optional.
     *
     * A patient who phones asking for her repeat lab slip does not generate a
     * visit, and neither does a study ordered ahead of an appointment so the
     * result is on the table when she arrives. Both used to force an empty
     * consultation that then lived in her history for good.
     */
    public function storeForPatient(StoreStudyOrderRequest $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId); // 404 outside the active workspace

        $data = $request->validated();
        $record = null;

        if (! empty($data['medical_record_id'])) {
            $record = MedicalRecord::findOrFail($data['medical_record_id']);

            // A consultation from a different patient would file the order — and
            // later its result — under the wrong chart.
            abort_if($record->patient_id !== $patient->id, 422, 'La consulta es de otro paciente.');
        }

        // With no consultation behind it, `clinical_info` is the only thing on
        // the order that says why the study was requested. The PDF prints it
        // under "Diagnóstico / Indicación clínica", and it is what a laboratory
        // and an insurer ask for.
        if (! $record && blank($data['clinical_info'] ?? null)) {
            throw ValidationException::withMessages([
                'clinical_info' => 'Sin una consulta asociada, indica el diagnóstico o el motivo del examen.',
            ]);
        }

        return $this->persist($patient->id, $record, $data);
    }

    /**
     * Shared write path for both entry points.
     *
     * `$patientId` is resolved server-side — from the scoped consultation or the
     * scoped patient — and never taken from the request body.
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(int $patientId, ?MedicalRecord $record, array $data): JsonResponse
    {
        $order = DB::transaction(function () use ($patientId, $record, $data) {
            $order = StudyOrder::create([
                // workspace_id auto-filled by BelongsToWorkspace.
                'medical_record_id' => $record?->id,
                'patient_id' => $patientId,
                'created_by' => Auth::user()->doctor?->id,
                'type' => $data['type'],
                'priority' => $data['priority'] ?? 'routine',
                'clinical_info' => $data['clinical_info'] ?? null,
                'notes' => $data['notes'] ?? null,
                'to_be_performed_at' => $data['to_be_performed_at'] ?? null,
            ]);

            // Canonical study snapshots: a provided study_id is only honoured when
            // it resolves to a study visible to this workspace (global or own),
            // preventing cross-workspace catalog id references.
            $visible = $this->visibleStudies($data['items']);

            foreach ($data['items'] as $item) {
                $study = isset($item['study_id']) ? ($visible[$item['study_id']] ?? null) : null;

                $order->items()->create([
                    'study_id' => $study?->id,
                    'name' => $study->name ?? $item['name'],
                    'category' => $study->category ?? ($item['category'] ?? null),
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            return $order;
        });

        $order->load(['patient', 'items', 'medicalRecord']);

        return StudyOrderResource::make($order)->response()->setStatusCode(201);
    }

    public function show(int $id, AccessLogger $accessLogger): StudyOrderResource
    {
        $order = StudyOrder::with(['patient', 'items', 'results'])->findOrFail($id);

        // LODP: an order exposes patient + clinical indication.
        $accessLogger->log('study_order', $order->id);

        return StudyOrderResource::make($order);
    }

    /** Stream the order PDF (Spanish, patient-facing). */
    public function pdf(int $id, StudyOrderService $service, AccessLogger $accessLogger): Response
    {
        $order = StudyOrder::findOrFail($id);

        $accessLogger->log('study_order', $order->id, 'study_order_pdf');

        $slug = $order->type === StudyOrder::TYPE_IMAGING ? 'imagenologia' : 'laboratorio';

        return $service->pdf($order)->download("orden-{$slug}-{$order->id}.pdf");
    }

    /**
     * Mark whether the study came back.
     *
     * The order used to be fire-and-forget, so nobody could answer "¿ya llegó el
     * resultado?" without asking the laboratory. The result document itself is
     * uploaded through the existing attachment endpoint carrying this order's
     * id — no second upload path, no second set of privacy rules.
     */
    public function updateResult(Request $request, int $id): StudyOrderResource
    {
        $order = StudyOrder::findOrFail($id);

        $data = $request->validate([
            'result_status' => ['required', Rule::in(array_keys(StudyOrder::RESULT_STATUSES))],
            'result_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $order->update([
            'result_status' => $data['result_status'],
            'result_notes' => $data['result_notes'] ?? $order->result_notes,
            // Stamped when it arrives, cleared if it goes back to pending, so
            // the date never outlives the fact it refers to.
            'result_received_at' => $data['result_status'] === 'completed'
                ? ($order->result_received_at ?? now())
                : null,
        ]);

        $order->load(['patient', 'items', 'results']);

        return StudyOrderResource::make($order);
    }

    public function destroy(int $id): JsonResponse
    {
        $order = StudyOrder::findOrFail($id);
        $order->delete();

        return response()->json(status: 204);
    }

    /**
     * Resolve the catalog studies referenced by the items that the active
     * workspace is allowed to see (global + own), keyed by id.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, Study>
     */
    private function visibleStudies(array $items): Collection
    {
        $ids = collect($items)->pluck('study_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $workspaceId = app(ActiveWorkspace::class)->id();

        return Study::query()
            ->whereKey($ids)
            ->where(function ($q) use ($workspaceId): void {
                $q->whereNull('workspace_id');
                if ($workspaceId !== null) {
                    $q->orWhere('workspace_id', $workspaceId);
                }
            })
            ->get()
            ->keyBy('id');
    }
}
