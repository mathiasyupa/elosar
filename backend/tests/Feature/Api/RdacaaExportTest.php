<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

it('exports the RDACAA CSV: one row per completed attention, scoped, and logs the access', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'document_type' => 'cedula']);

    $rec = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'status' => 'completed',
    ]);
    $rec->diagnoses()->create(['cie10_code' => 'J06.9', 'cie10_description' => 'IRA superior', 'type' => 'presumptive']);

    // Foreign workspace attention that must NOT appear in the file.
    $otherWs = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    MedicalRecord::factory()->create([
        'workspace_id' => $otherWs->id,
        'patient_id' => $otherPatient->id,
        'status' => 'completed',
    ]);

    Sanctum::actingAs($user);

    $response = $this->get('/api/v1/reports/rdacaa', clinicalHeaders($ws->id));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');

    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv)), fn ($l) => $l !== ''));

    expect($csv)->toContain('establecimiento;fecha_atencion')      // header row
        ->and($lines)->toHaveCount(2)                              // header + our single attention
        ->and($csv)->toContain('PRE')                              // presumptive → PRE
        ->and($csv)->toContain('PRIMERA');                         // patient's first-ever attention

    $this->assertDatabaseHas('access_logs', [
        'subject_type' => 'rdacaa_export',
        'action' => 'export',
        'workspace_id' => $ws->id,
    ]);
});
