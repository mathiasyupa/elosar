<?php

use App\Models\Patient;
use App\Models\PatientGynecoHistory;
use App\Models\Pregnancy;
use App\Models\Specialty;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Gyneco-obstetric history — of the patient, not of each consultation
|--------------------------------------------------------------------------
| One row per patient, upserted. What matters here: it does not collide with the
| free-text personal background (the bug this table exists to avoid), the front
| desk cannot read it, and the declared obstetric formula is never overwritten by
| what this system happens to have registered.
*/

function gynecoHistoryScenario(): array
{
    $scenario = makeClinicScenario();
    $specialty = Specialty::query()->firstOrCreate(['name' => 'Ginecología y Obstetricia']);
    $specialty->capabilities = [Specialty::CAPABILITY_GYNECO_OBSTETRICS];
    $specialty->save();

    $scenario['ownerDoctor']->specialties()->syncWithoutDetaching([$specialty->id => ['is_primary' => false]]);

    return $scenario;
}

it('creates the row on first write and corrects it afterwards', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}/gyneco-history", [
            'menarche_age' => 12,
            'gestations'   => 2,
        ])
        ->assertOk();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}/gyneco-history", ['menarche_age' => 13])
        ->assertOk();

    expect(PatientGynecoHistory::where('patient_id', $patient->id)->count())->toBe(1)
        ->and(PatientGynecoHistory::where('patient_id', $patient->id)->first()->menarche_age)->toBe(13)
        // A partial update must not wipe what it did not mention.
        ->and(PatientGynecoHistory::where('patient_id', $patient->id)->first()->gestations)->toBe(2);
});

it('answers with an empty history instead of 404 for a patient who has none', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/patients/{$patient->id}/gyneco-history")
        ->assertOk()
        ->assertJsonPath('data.menarche_age', null);
});

/**
 * The reason this is a table and not another key in `personal_background`:
 * that JSONB is replaced wholesale on every write, so anything the writing
 * client does not know about disappears silently. Saving one must not touch the
 * other, in either order.
 */
it('does not collide with the free-text personal background', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create([
        'workspace_id'        => $s['clinic']->id,
        'personal_background' => ['allergies' => 'Penicilina'],
    ]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}/gyneco-history", ['menarche_age' => 12])
        ->assertOk();

    // Now the other screen saves the personal background the only way it knows.
    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}", [
            'personal_background' => ['allergies' => 'Penicilina', 'surgeries' => 'Apendicectomía'],
        ])
        ->assertOk();

    expect(PatientGynecoHistory::where('patient_id', $patient->id)->first()->menarche_age)->toBe(12)
        ->and($patient->fresh()->personal_background['surgeries'])->toBe('Apendicectomía');
});

it('reports the declared formula and what this system registered, separately', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    // The patient reports four pregnancies; this clinic has only ever seen one.
    PatientGynecoHistory::factory()->forPatient($patient)->create(['gestations' => 4]);
    Pregnancy::factory()->forPatient($patient)->create();

    $data = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/patients/{$patient->id}/gyneco-history")
        ->assertOk()
        ->json('data');

    expect($data['declared']['gestations'])->toBe(4)
        ->and($data['registered_in_elosar']['gestations'])->toBe(1)
        ->and($data['registered_in_elosar']['ongoing'])->toBe(1);
});

it('never overwrites the declared formula with the registered one', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    PatientGynecoHistory::factory()->forPatient($patient)->create(['gestations' => 4]);
    Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}/gyneco-history", ['menarche_age' => 12])
        ->assertOk();

    expect(PatientGynecoHistory::where('patient_id', $patient->id)->first()->gestations)->toBe(4);
});

it('keeps the front desk out of the gyneco history', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['recUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/patients/{$patient->id}/gyneco-history")
        ->assertForbidden();
});

it('returns 404 for a patient of another clinic', function () {
    $mine = gynecoHistoryScenario();
    $theirs = gynecoHistoryScenario();
    $otherPatient = Patient::factory()->create(['workspace_id' => $theirs['clinic']->id]);

    Sanctum::actingAs($mine['ownerUser']);

    $this->withHeader('X-Workspace-Id', (string) $mine['clinic']->id)
        ->getJson("/api/v1/patients/{$otherPatient->id}/gyneco-history")
        ->assertNotFound();
});

it('rejects a contraceptive method outside the closed set', function () {
    $s = gynecoHistoryScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/patients/{$patient->id}/gyneco-history", [
            'contraceptive_method' => 'telepatia',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('contraceptive_method');
});
