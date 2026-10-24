<?php

use App\Models\Patient;
use App\Models\PatientImmunization;
use App\Models\Vaccine;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function vaxHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Catalog
// ─────────────────────────────────────────────

it('returns the active vaccine catalog', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Vaccine::factory()->create(['name' => 'BCG - Tuberculosis', 'max_doses' => 1, 'max_boosters' => 0]);
    Vaccine::factory()->create(['name' => 'Pentavalente (DPT + HB + Hib)', 'max_doses' => 3]);

    Sanctum::actingAs($user);

    $names = $this->getJson('/api/v1/vaccines', vaxHeaders($ws->id))
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toContain('BCG - Tuberculosis')->toContain('Pentavalente (DPT + HB + Hib)');
});

// ─────────────────────────────────────────────
// Record / list / delete
// ─────────────────────────────────────────────

it('records an administered dose attributed to the acting user', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $vaccine = Vaccine::factory()->create(['name' => 'SRP (Sarampión, Rubéola, Paperas)', 'max_doses' => 2]);

    Sanctum::actingAs($user);

    $data = $this->postJson("/api/v1/patients/{$patient->id}/immunizations", [
        'vaccine_id'      => $vaccine->id,
        'vaccine_name'    => $vaccine->name,
        'dose_label'      => 'dose_1',
        'administered_at' => '2026-06-20',
        'lot'             => 'LOT-998',
        'reminder_at'     => '2026-12-20',
    ], vaxHeaders($ws->id))->assertStatus(201)->json('data');

    expect($data['dose_label_text'])->toBe('Dosis 1')
        ->and($data['lot'])->toBe('LOT-998');

    $this->assertDatabaseHas('patient_immunizations', [
        'id'                      => $data['id'],
        'workspace_id'            => $ws->id,
        'patient_id'              => $patient->id,
        'vaccine_id'              => $vaccine->id,
        'dose_label'              => 'dose_1',
        'administered_by_user_id' => $user->id,
    ]);
});

it('lists a patient vaccination card and deletes a dose', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $dose = PatientImmunization::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'vaccine_name' => 'BCG',
        'dose_label'   => 'single',
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/patients/{$patient->id}/immunizations", vaxHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.0.vaccine_name', 'BCG');

    $this->deleteJson("/api/v1/immunizations/{$dose->id}", [], vaxHeaders($ws->id))->assertNoContent();
    $this->assertDatabaseMissing('patient_immunizations', ['id' => $dose->id, 'deleted_at' => null]);
});

// ─────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────

it('rejects an invalid dose label', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$patient->id}/immunizations", [
        'vaccine_name'    => 'BCG',
        'dose_label'      => 'dose_9', // not a valid slot
        'administered_at' => '2026-06-20',
    ], vaxHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['dose_label']);
});

// ─────────────────────────────────────────────
// Isolation
// ─────────────────────────────────────────────

it('returns 404 recording a dose for a foreign-workspace patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$foreignPatient->id}/immunizations", [
        'vaccine_name'    => 'BCG',
        'dose_label'      => 'single',
        'administered_at' => '2026-06-20',
    ], vaxHeaders($ws->id))->assertNotFound();

    $this->assertDatabaseMissing('patient_immunizations', ['patient_id' => $foreignPatient->id]);
});

// ─────────────────────────────────────────────
// Permissions
// ─────────────────────────────────────────────

it('forbids reception (no vitals.create) from touching the vaccination card', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    Sanctum::actingAs($scenario['recUser']);

    $this->getJson("/api/v1/patients/{$patient->id}/immunizations", vaxHeaders($clinic->id))->assertForbidden();
    $this->postJson("/api/v1/patients/{$patient->id}/immunizations", [
        'vaccine_name'    => 'BCG',
        'dose_label'      => 'single',
        'administered_at' => '2026-06-20',
    ], vaxHeaders($clinic->id))->assertForbidden();
});

it('lets a nurse record a vaccination (vitals.create)', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    Sanctum::actingAs($scenario['nurseUser']);

    $this->postJson("/api/v1/patients/{$patient->id}/immunizations", [
        'vaccine_name'    => 'Influenza',
        'dose_label'      => 'dose_1',
        'administered_at' => '2026-06-20',
    ], vaxHeaders($clinic->id))->assertStatus(201);
});
