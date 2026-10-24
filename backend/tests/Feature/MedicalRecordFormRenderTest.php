<?php

use App\Filament\Resources\MedicalRecords\MedicalRecordResource;
use App\Models\Doctor;
use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\Support\Facades\Storage;

/**
 * Smoke test: the medical record edit page (with the hardened attachments
 * repeater + per-item download action) must still render. A broken Repeater
 * action API would otherwise silently break the core clinical workflow.
 *
 * Uses the super_admin gate bypass (userWithRole + bypass) plus a linked doctor
 * so the fail-closed tenant scope resolves to the record's workspace.
 */
it('renders the medical record edit page with a hardened attachments repeater', function () {
    Storage::fake('local');

    $user   = userWithRole('super_admin');
    $doctor = Doctor::factory()->withUser($user)->create();
    $doctor->refresh();
    actingAsDoctor($doctor);

    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id]);

    $record = MedicalRecord::create([
        'workspace_id'            => $doctor->workspace_id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Smoke test',
        'diagnosis'               => 'Dx',
        'treatment'               => 'Tx',
    ]);

    Storage::disk('local')->put('medical-attachments/x.pdf', '%PDF-1.4');
    MedicalAttachment::create([
        'medical_record_id' => $record->id,
        'title'             => 'X-ray',
        'file_path'         => 'medical-attachments/x.pdf',
    ]);

    $this->get(MedicalRecordResource::getUrl('edit', ['record' => $record->getRouteKey()]))
        ->assertOk();
});
