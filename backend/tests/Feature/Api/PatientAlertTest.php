<?php

use App\Models\Patient;
use App\Models\PatientAlert;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Standing patient alerts
|--------------------------------------------------------------------------
| The competitor offers three anonymous free-text slots. These carry who wrote
| them, when, and whether they still apply — and they are RESOLVED, not deleted,
| because "this stopped applying on such a date" is clinical information.
*/

function alertPatient(int $workspaceId): Patient
{
    return Patient::factory()->create(['workspace_id' => $workspaceId]);
}

it('records who raised the alert', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = alertPatient($s['clinic']->id);

    $id = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/alerts", [
            'text'     => 'Portadora de marcapasos',
            'severity' => 'critical',
        ])
        ->assertCreated()
        ->json('data.id');

    $alert = PatientAlert::findOrFail($id);

    expect($alert->created_by_user_id)->toBe($s['ownerUser']->id)
        ->and($alert->severity)->toBe('critical')
        ->and($alert->resolved_at)->toBeNull();
});

/**
 * Resolving keeps the row. An alert that can be made to vanish is an alert
 * nobody can audit afterwards.
 */
it('keeps a resolved alert instead of removing it', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = alertPatient($s['clinic']->id);
    $alert = PatientAlert::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
    ]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patient-alerts/{$alert->id}/resolve")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $fresh = $alert->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->resolved_at)->not->toBeNull()
        ->and($fresh->resolved_by_user_id)->toBe($s['ownerUser']->id);
});

/** The patient carries only the live ones: a closed alert must not keep shouting. */
it('travels with the patient, and only while it is still active', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = alertPatient($s['clinic']->id);

    PatientAlert::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'text'         => 'Vigente',
    ]);
    PatientAlert::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'text'         => 'Ya no aplica',
        'resolved_at'  => now(),
    ]);

    $alerts = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/patients/{$patient->id}")
        ->assertOk()
        ->json('data.alerts');

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['text'])->toBe('Vigente');
});

it('refuses an alert too long to sit next to the patient name', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = alertPatient($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/alerts", ['text' => str_repeat('a', 300)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('text');
});

it('returns 404 for an alert of another clinic', function () {
    $mine = makeClinicScenario();
    $theirs = makeClinicScenario();
    $otherPatient = alertPatient($theirs['clinic']->id);
    $otherAlert = PatientAlert::factory()->create([
        'workspace_id' => $theirs['clinic']->id,
        'patient_id'   => $otherPatient->id,
    ]);

    Sanctum::actingAs($mine['ownerUser']);

    $this->withHeader('X-Workspace-Id', (string) $mine['clinic']->id)
        ->postJson("/api/v1/patient-alerts/{$otherAlert->id}/resolve")
        ->assertNotFound();
});

it('keeps the front desk from writing clinical warnings', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['recUser']);
    $patient = alertPatient($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/alerts", ['text' => 'No debería poder'])
        ->assertForbidden();
});
