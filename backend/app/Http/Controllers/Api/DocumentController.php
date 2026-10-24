<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalAttachment;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\StudyOrder;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Workspace-wide & patient-specific document library ("Documentos" screen).
 * Consolidates manual file attachments, generated prescription PDFs, medical
 * certificates, and lab/imaging study orders into a single list grouped by type
 * or patient.
 */
class DocumentController extends Controller
{
    public function index(Request $request, AccessLogger $accessLogger): JsonResponse
    {
        $patientId = $request->integer('patient_id') ?: null;
        if ($patientId !== null) {
            Patient::findOrFail($patientId);
            $accessLogger->log('patient', $patientId, 'documents_list');
        } else {
            $accessLogger->log('document_library', app(ActiveWorkspace::class)->id(), 'list');
        }

        return response()->json([
            'data' => $this->collectDocuments($patientId),
        ]);
    }

    /**
     * Collect and merge all workspace document types for global library or patient view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function collectDocuments(?int $patientId = null): array
    {
        // 1. Manual file attachments (consultation attachments or direct patient files)
        $attachmentsQuery = MedicalAttachment::query()->with(['patient', 'medicalRecord.patient']);
        if ($patientId) {
            $attachmentsQuery->where(function ($q) use ($patientId) {
                $q->where('patient_id', $patientId)
                    ->orWhereHas('medicalRecord', fn ($mr) => $mr->where('patient_id', $patientId));
            });
        }

        $attachments = $attachmentsQuery->latest('id')->get()
            ->map(fn (MedicalAttachment $a): array => $this->attachmentRow($a, $a->patient ?? $a->medicalRecord?->patient));

        // 2. Prescriptions issued (MedicalRecords containing RecordPrescription items)
        $prescriptionsQuery = MedicalRecord::query()->has('prescriptions')->with(['patient', 'prescriptions']);
        if ($patientId) {
            $prescriptionsQuery->where('patient_id', $patientId);
        }

        $prescriptions = $prescriptionsQuery->latest('id')->get()->map(function (MedicalRecord $r): array {
            $medNames = $r->prescriptions->pluck('medication_name')->filter()->implode(', ');
            $title = 'Receta médica'.($medNames ? ": {$medNames}" : '');

            return [
                'id' => 'rx-'.$r->id,
                'numeric_id' => $r->id,
                'source' => 'prescription',
                'type' => 'prescription',
                'title' => $title,
                'notes' => $r->reason_for_consultation,
                'record_id' => $r->id,
                'record_date' => $r->created_at?->toIso8601String(),
                'download_url' => "/api/v1/records/{$r->id}/prescription",
                'extension' => 'pdf',
                'patient' => $r->patient ? [
                    'id' => $r->patient->id,
                    'name' => $r->patient->name,
                    'id_number' => $r->patient->id_number,
                ] : null,
            ];
        });

        // 3. Medical certificates issued
        $certificatesQuery = MedicalCertificate::query()->with('patient');
        if ($patientId) {
            $certificatesQuery->where('patient_id', $patientId);
        }

        $certificates = $certificatesQuery->latest('id')->get()->map(function (MedicalCertificate $c): array {
            return [
                'id' => 'cert-'.$c->id,
                'numeric_id' => $c->id,
                'source' => 'certificate',
                'type' => 'certificate',
                'title' => $c->typeLabel(),
                'notes' => $c->symptoms ?? $c->body,
                'record_id' => $c->medical_record_id,
                'record_date' => $c->created_at?->toIso8601String(),
                'download_url' => "/api/v1/certificates/{$c->id}/pdf",
                'extension' => 'pdf',
                'patient' => $c->patient ? [
                    'id' => $c->patient->id,
                    'name' => $c->patient->name,
                    'id_number' => $c->patient->id_number,
                ] : null,
            ];
        });

        // 4. Study orders (lab & imaging orders)
        $ordersQuery = StudyOrder::query()->with(['patient', 'items']);
        if ($patientId) {
            $ordersQuery->where('patient_id', $patientId);
        }

        $orders = $ordersQuery->latest('id')->get()->map(function (StudyOrder $o): array {
            $itemNames = $o->items->pluck('name')->filter()->implode(', ');
            $label = $o->typeLabel();
            $title = "Orden de {$label}".($itemNames ? ": {$itemNames}" : '');

            return [
                'id' => 'order-'.$o->id,
                'numeric_id' => $o->id,
                'source' => 'study_order',
                'type' => $o->type === 'imaging' ? 'image' : 'lab',
                'title' => $title,
                'notes' => $o->clinical_info,
                'record_id' => $o->medical_record_id,
                'record_date' => $o->created_at?->toIso8601String(),
                'download_url' => "/api/v1/study-orders/{$o->id}/pdf",
                'extension' => 'pdf',
                'patient' => $o->patient ? [
                    'id' => $o->patient->id,
                    'name' => $o->patient->name,
                    'id_number' => $o->patient->id_number,
                ] : null,
            ];
        });

        return $attachments
            ->concat($prescriptions)
            ->concat($certificates)
            ->concat($orders)
            ->sortByDesc('record_date')
            ->values()
            ->all();
    }

    public function patientDocuments(int $patientId, AccessLogger $accessLogger): JsonResponse
    {
        Patient::findOrFail($patientId);
        $accessLogger->log('patient', $patientId, 'documents_list');

        return response()->json([
            'data' => $this->collectDocuments($patientId),
        ]);
    }

    public function storePatientDocument(Request $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx'],
            'title' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', Rule::in(MedicalAttachment::CATEGORIES)],
            'medical_record_id' => ['nullable', 'integer'],
        ]);

        $medicalRecordId = $validated['medical_record_id'] ?? null;
        if ($medicalRecordId !== null) {
            MedicalRecord::query()
                ->where('patient_id', $patient->id)
                ->findOrFail($medicalRecordId);
        }

        $file = $request->file('file');
        $title = ! empty($validated['title']) ? $validated['title'] : $file->getClientOriginalName();
        $disk = Storage::disk('local');
        $path = $file->store("attachments/{$patient->workspace_id}/{$patient->id}", 'local');

        try {
            $attachment = MedicalAttachment::create([
                'workspace_id' => $patient->workspace_id,
                'patient_id' => $patient->id,
                'medical_record_id' => $medicalRecordId,
                'title' => $title,
                'category' => $validated['category'] ?? null,
                'file_path' => $path,
                'notes' => $validated['notes'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }

        return response()->json(['data' => $this->attachmentRow($attachment, $patient)], 201);
    }

    /**
     * An uploaded file as the library lists it. `type` is the category chosen
     * at upload, or `attachment` for older files (the web still infers those).
     *
     * @return array<string, mixed>
     */
    private function attachmentRow(MedicalAttachment $a, ?Patient $patient): array
    {
        return [
            'id' => 'att-'.$a->id,
            'numeric_id' => $a->id,
            'source' => 'attachment',
            'type' => $a->category ?? 'attachment',
            'category' => $a->category,
            'title' => $a->title,
            'notes' => $a->notes,
            'record_id' => $a->medical_record_id,
            'record_date' => $a->created_at?->toIso8601String(),
            'download_url' => "/api/v1/attachments/{$a->id}/download",
            'extension' => strtolower(pathinfo((string) $a->file_path, PATHINFO_EXTENSION)) ?: null,
            'patient' => $patient ? [
                'id' => $patient->id,
                'name' => $patient->name,
                'id_number' => $patient->id_number,
            ] : null,
        ];
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
        ]);

        return $this->storePatientDocument($request, (int) $validated['patient_id']);
    }
}
