<?php

use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

/**
 * Medical attachments are PHI: stored on a private disk and served only through
 * the authenticated, workspace-scoped download route. See
 * App\Http\Controllers\MedicalAttachmentController.
 */
function makeAttachment(int $workspaceId, int $patientId, int $doctorId): MedicalAttachment
{
    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspaceId,
        'patient_id'              => $patientId,
        'created_by'              => $doctorId,
        'reason_for_consultation' => 'Test',
        'diagnosis'               => 'Dx',
        'treatment'               => 'Tx',
    ]);

    $path = 'medical-attachments/' . uniqid() . '.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 fake content');

    return MedicalAttachment::create([
        'medical_record_id' => $record->id,
        'title'             => 'Chest X-ray',
        'file_path'         => $path,
    ]);
}

it('streams an attachment to a doctor in the owning workspace', function () {
    Storage::fake('local');
    $doctor  = actingAsDoctor();
    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id]);

    $attachment = makeAttachment($doctor->workspace_id, $patient->id, $doctor->id);

    $this->get(route('medical-attachments.download', $attachment))
        ->assertOk()
        ->assertDownload('Chest X-ray.pdf');
});

it('denies downloading an attachment from another workspace', function () {
    Storage::fake('local');
    $doctor = actingAsDoctor();

    $otherWs      = Workspace::factory()->create();
    $otherPatient = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $otherWs->id]));
    $attachment   = makeAttachment($otherWs->id, $otherPatient->id, $doctor->id);

    $this->get(route('medical-attachments.download', $attachment))
        ->assertForbidden();
});

it('requires authentication to download an attachment', function () {
    Storage::fake('local');
    [$doctor, , $ws] = makeDoctorWithWorkspace();
    $patient    = Patient::withoutGlobalScopes()->create(minimalPatientData(['workspace_id' => $ws->id]));
    $attachment = makeAttachment($ws->id, $patient->id, $doctor->id);

    $this->get(route('medical-attachments.download', $attachment))
        ->assertRedirect();
});
