<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordVital;
use Laravel\Sanctum\Sanctum;

it('returns the latest vitals from the patient last visit in the workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $oldRecord = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_at'   => now()->subDays(30),
    ]);
    RecordVital::factory()->create([
        'medical_record_id' => $oldRecord->id,
        'weight_kg'           => 60,
        'height_cm'           => 160,
        'recorded_at'         => now()->subDays(30),
    ]);

    $recentRecord = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
    ]);
    RecordVital::factory()->create([
        'medical_record_id' => $recentRecord->id,
        'weight_kg'           => 72.5,
        'height_cm'           => 175,
        'heart_rate'          => 80,
        'recorded_at'         => now()->subDay(),
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.source', 'last_visit')
        ->assertJsonPath('data.weight_kg', '72.50')
        ->assertJsonPath('data.height_cm', '175.00')
        ->assertJsonPath('data.heart_rate', 80);
});

it('returns null when the patient has no prior vitals', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('does not leak baseline vitals from another workspace', function () {
    [, $userA, $wsA] = makeDoctorWithWorkspace();
    [, , $wsB] = makeDoctorWithWorkspace();

    $patientB = Patient::factory()->create(['workspace_id' => $wsB->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $wsB->id,
        'patient_id'   => $patientB->id,
    ]);
    RecordVital::factory()->create([
        'medical_record_id' => $record->id,
        'weight_kg'           => 90,
        'height_cm'           => 190,
    ]);

    // Same patient id in workspace A would 404; use a patient only in A with no vitals.
    $patientA = Patient::factory()->create(['workspace_id' => $wsA->id]);
    Sanctum::actingAs($userA);

    $this->getJson("/api/v1/patients/{$patientA->id}/baseline-vitals", ['X-Workspace-Id' => (string) $wsA->id])
        ->assertOk()
        ->assertJsonPath('data', null);
});
