<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMedicalCertificateRequest;
use App\Http\Resources\MedicalCertificateResource;
use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Services\CertificateService;
use App\Support\AccessLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Medical certificates (constancia, reposo, aptitud) for a patient.
 * PDFs reuse the workspace letterhead configured in Ajustes.
 */
class MedicalCertificateController extends Controller
{
    public function index(int $id): JsonResponse
    {
        Patient::findOrFail($id);

        $certificates = MedicalCertificate::query()
            ->where('patient_id', $id)
            ->with('doctor')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(20);

        return MedicalCertificateResource::collection($certificates)->response();
    }

    public function store(StoreMedicalCertificateRequest $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        $doctorId = Auth::user()->doctor?->id;
        abort_unless($doctorId, 403);

        $certificate = MedicalCertificate::create($this->attributesFor($patient, $request->validated(), $doctorId));

        return MedicalCertificateResource::make($certificate->load('doctor'))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Fix a certificate already issued: a wrong date, a word, whether the
     * CIE-10 prints. It keeps its patient, its signing doctor and its issue
     * date, and only the doctor who signed it may change it.
     */
    public function update(StoreMedicalCertificateRequest $request, int $id): MedicalCertificateResource
    {
        $certificate = MedicalCertificate::findOrFail($id);
        $doctorId = Auth::user()->doctor?->id;
        abort_unless($doctorId && (int) $certificate->created_by === (int) $doctorId, 403, 'Solo el médico que firmó el certificado puede editarlo.');

        $attributes = $this->attributesFor($certificate->patient()->firstOrFail(), $request->validated(), $doctorId);
        unset($attributes['patient_id'], $attributes['created_by']);
        $attributes['issued_at'] = $request->validated()['issued_at'] ?? $certificate->issued_at;
        $certificate->update($attributes);

        return MedicalCertificateResource::make($certificate->fresh('doctor'));
    }

    public function show(int $id): MedicalCertificateResource
    {
        $certificate = MedicalCertificate::with('doctor')->findOrFail($id);

        return MedicalCertificateResource::make($certificate);
    }

    public function pdf(int $id, CertificateService $service, AccessLogger $accessLogger): Response
    {
        $certificate = MedicalCertificate::findOrFail($id);

        $accessLogger->log('medical_certificate', $certificate->id, 'certificate_pdf');

        return $service->pdf($certificate)->download("certificado-{$certificate->id}.pdf");
    }

    /** Inline PDF for preview in the browser before downloading. */
    public function preview(int $id, CertificateService $service, AccessLogger $accessLogger): Response
    {
        $certificate = MedicalCertificate::findOrFail($id);

        $accessLogger->log('medical_certificate', $certificate->id, 'cert_preview');

        return $service->pdf($certificate)->stream("certificado-{$certificate->id}.pdf");
    }

    /**
     * The certificate being written, as a PDF, without saving it — so the
     * form can show the real document beside it while the doctor types.
     */
    public function previewDraft(StoreMedicalCertificateRequest $request, int $id, CertificateService $service): Response
    {
        $patient = Patient::findOrFail($id);
        $doctor = Auth::user()->doctor;
        abort_unless($doctor, 403);

        $draft = new MedicalCertificate($this->attributesFor($patient, $request->validated(), $doctor->id));
        $draft->workspace_id = $patient->workspace_id;
        $draft->setRelation('patient', $patient);
        $draft->setRelation('doctor', $doctor);

        return $service->pdf($draft)->stream('certificado-borrador.pdf');
    }

    /**
     * Columns of a certificate from the validated form.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFor(Patient $patient, array $validated, int $doctorId): array
    {
        return [
            'patient_id' => $patient->id,
            'created_by' => $doctorId,
            'medical_record_id' => $validated['medical_record_id'] ?? null,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'body' => $validated['body'] ?? '',
            'rest_days' => $validated['rest_days'] ?? null,
            'valid_from' => $validated['valid_from'] ?? null,
            'valid_until' => $validated['valid_until'] ?? null,
            'issued_at' => $validated['issued_at'] ?? now()->toDateString(),

            // Parameterised certificate. What the clinic already knows about the
            // patient is filled in from their file so nobody retypes it.
            'city' => $validated['city'] ?? $patient->city,
            'hc_number' => $validated['hc_number'] ?? $patient->id_number,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? $patient->phone,
            'contingency_type' => $validated['contingency_type'] ?? null,
            'company' => $validated['company'] ?? null,
            'activity' => $validated['activity'] ?? null,
            'attended_at' => $validated['attended_at'] ?? null,
            'symptoms' => $validated['symptoms'] ?? null,
            'diagnosis' => $validated['diagnosis'] ?? null,
            'cie10_codes' => $validated['cie10_codes'] ?? null,
            'show_cie10' => $validated['show_cie10'] ?? true,
            'treatment' => $validated['treatment'] ?? null,
            'absolute_rest' => $validated['absolute_rest'] ?? null,
        ];
    }

    public function duplicate(int $id): JsonResponse
    {
        $original = MedicalCertificate::findOrFail($id);

        $copy = $original->replicate([
            'created_at',
            'updated_at',
        ]);
        $copy->issued_at = now()->toDateString();
        $copy->save();

        return MedicalCertificateResource::make($copy->load('doctor'))
            ->response()
            ->setStatusCode(201);
    }
}
