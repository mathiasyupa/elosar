<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\PatientVitalObservation;
use Laravel\Sanctum\Sanctum;

function observationHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('allows a nurse with reception and cashier duties to record vitals without a consultation', function () {
    $s = makeClinicScenario();
    setMemberRoles($s, 'recUser', ['receptionist', 'billing', 'nurse']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = observationHeaders($s['clinic']->id);
    Sanctum::actingAs($s['recUser']);

    $response = $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", [
        'weight_kg' => '70.5',
        'height_cm' => '175',
        'head_circumference_cm' => '55',
        'heart_rate' => 72,
        'workspace_id' => 999999,
        'recorded_by_user_id' => $s['ownerUser']->id,
        'score_mama_total' => 0,
    ], $h);

    $response->assertStatus(422)->assertJsonValidationErrors('score_mama_total');

    $response = $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", [
        'weight_kg' => '70.5',
        'height_cm' => '175',
        'head_circumference_cm' => '55',
        'heart_rate' => 72,
        'workspace_id' => 999999,
        'recorded_by_user_id' => $s['ownerUser']->id,
        'diagnosis' => 'This must never be saved as a consultation',
    ], $h)->assertCreated()
        ->assertJsonPath('data.source', 'standalone')
        ->assertJsonPath('data.patient_id', $patient->id);

    $id = $response->json('data.id');
    $this->assertDatabaseHas('patient_vital_observations', [
        'id' => $id,
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'recorded_by_user_id' => $s['recUser']->id,
        'heart_rate' => 72,
    ]);
    expect($response->json('data.bmi'))->not->toBeNull()
        ->and($response->json('data'))->not->toHaveKey('diagnosis');
    expect(MedicalRecord::query()->where('patient_id', $patient->id)->count())->toBe(0);

    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", $h)
        ->assertOk()
        ->assertJsonPath('data.source', 'standalone')
        ->assertJsonPath('data.heart_rate', 72)
        ->assertJsonPath('data.head_circumference_cm', '55.00');
    $this->assertDatabaseHas('access_logs', [
        'workspace_id' => $s['clinic']->id,
        'user_id' => $s['recUser']->id,
        'subject_type' => 'patient_vitals',
        'subject_id' => $patient->id,
        'action' => 'view',
    ]);
});

it('denies patient vitals to cashier and receptionist roles without nursing', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = observationHeaders($s['clinic']->id);

    Sanctum::actingAs($s['recUser']);
    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", ['heart_rate' => 72], $h)->assertForbidden();
    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", $h)->assertForbidden();

    setMemberRoles($s, 'recUser', ['billing']);
    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", ['heart_rate' => 72], $h)->assertForbidden();
});

it('uses the most recent measurement when a consultation follows standalone triage', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = observationHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", ['heart_rate' => 72], $h)
        ->assertCreated();

    $record = MedicalRecord::create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'created_by' => $s['memDoctor']->id,
        'status' => 'completed',
    ]);
    $record->vitals()->create(['heart_rate' => 80, 'recorded_at' => now()->addMinute()]);

    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", $h)
        ->assertOk()
        ->assertJsonPath('data.source', 'last_visit')
        ->assertJsonPath('data.heart_rate', 80);
});

it('returns 404 for a patient in another workspace and creates no observation', function () {
    $s = makeClinicScenario();
    $other = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $other['clinic']->id]);
    Sanctum::actingAs($s['nurseUser']);

    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", ['heart_rate' => 72], observationHeaders($s['clinic']->id))
        ->assertNotFound();
    $this->getJson("/api/v1/patients/{$patient->id}/baseline-vitals", observationHeaders($s['clinic']->id))
        ->assertNotFound();
    expect(PatientVitalObservation::withoutGlobalScopes()->count())->toBe(0);
});

it('rejects an empty observation and invalid measurements without writing', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    Sanctum::actingAs($s['nurseUser']);
    $h = observationHeaders($s['clinic']->id);

    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", [], $h)
        ->assertUnprocessable()->assertJsonValidationErrors('vitals');
    $this->postJson("/api/v1/patients/{$patient->id}/vital-observations", [
        'temperature' => 50,
        'oxygen_saturation' => 101,
    ], $h)->assertUnprocessable()->assertJsonValidationErrors(['temperature', 'oxygen_saturation']);

    expect(PatientVitalObservation::withoutGlobalScopes()->count())->toBe(0);
});
