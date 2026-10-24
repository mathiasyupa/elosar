<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Score MAMÁ — capture and scoring
|--------------------------------------------------------------------------
| The three parameters `record_vitals` did not carry, end to end: nursing takes
| them in triage, and the score that lands in the record is the SERVER'S.
*/

/** A triage draft for a patient of the clinic, ready for vitals. */
function triageDraft(int $workspaceId): MedicalRecord
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    return MedicalRecord::factory()->create([
        'workspace_id' => $workspaceId,
        'patient_id'   => $patient->id,
        'status'       => 'draft',
    ]);
}

/** The protocol's worked example, as the API receives it. */
function workedExampleVitals(): array
{
    return [
        'temperature'         => 36.5,
        'blood_pressure_sys'  => 140,
        'blood_pressure_dia'  => 80,
        'heart_rate'          => 105,
        'respiratory_rate'    => 22,
        'oxygen_saturation'   => 96,
        'oxygen_therapy'      => 'ambient_air',
        'consciousness_level' => 'alert',
        'urine_output_not_quantifiable' => true,
    ];
}

it('stores the three parameters the table was missing', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);
    $record = triageDraft($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", workedExampleVitals())
        ->assertOk();

    $vital = $record->fresh()->vitals()->latest('id')->first();

    expect($vital->oxygen_therapy)->toBe('ambient_air')
        ->and($vital->consciousness_level)->toBe('alert')
        ->and($vital->urine_output_not_quantifiable)->toBeTrue()
        ->and($vital->urine_output_ml_h)->toBeNull();
});

it('scores the reading server-side and stamps the protocol', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);
    $record = triageDraft($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", workedExampleVitals())
        ->assertOk();

    $vital = $record->fresh()->vitals()->latest('id')->first();

    expect($vital->score_mama_total)->toBe(3)
        ->and($vital->score_mama_level)->toBe('treat_and_refer')
        ->and($vital->score_mama_protocol)->toBe('MSP-2025');
});

/**
 * The one that matters. A client is a display, not a source of clinical truth: a
 * stale bundle — or anyone crafting the request — must not be able to write a
 * calm score over an alarming reading.
 */
it('ignores a score sent by the client and computes its own', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);
    $record = triageDraft($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", [
            // Una sistólica de 65 es emergencia obstétrica por sí sola…
            'blood_pressure_sys'  => 65,
            'consciousness_level' => 'alert',
            // …y el cliente insiste en que todo está normal.
            'score_mama_total'    => 0,
            'score_mama_level'    => 'normal',
            'score_mama_protocol' => 'inventado',
        ])
        ->assertOk();

    $vital = $record->fresh()->vitals()->latest('id')->first();

    expect($vital->score_mama_total)->toBe(3)
        ->and($vital->score_mama_level)->toBe('emergency')
        ->and($vital->score_mama_protocol)->toBe('MSP-2025');
});

it('rejects a value outside the closed set instead of storing it raw', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);
    $record = triageDraft($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", [
            'consciousness_level' => 'Alerta',   // la etiqueta española, no la clave
        ])
        ->assertStatus(422);
});

/**
 * A reading with nothing scoreable must not claim a calm 0 — that would put
 * "normal" in a record where nobody measured anything that the score reads.
 */
it('leaves the score null when the reading has nothing to score', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);
    $record = triageDraft($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", [
            'weight_kg' => 68.4,
            'height_cm' => 162,
        ])
        ->assertOk();

    $vital = $record->fresh()->vitals()->latest('id')->first();

    expect($vital->score_mama_total)->toBeNull()
        ->and($vital->score_mama_level)->toBeNull()
        ->and($vital->score_mama_protocol)->toBeNull();
});

/** The doctor's full-record path writes vitals too, and must score them the same. */
it('scores the same way when the doctor closes the consultation', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['memUser']);
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id' => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis' => 'Embarazo de 34 semanas',
            'treatment' => 'Control en 2 semanas',
            'vitals' => workedExampleVitals(),
        ])
        ->assertCreated();

    $vital = MedicalRecord::where('patient_id', $patient->id)->latest('id')->first()
        ->vitals()->latest('id')->first();

    expect($vital->score_mama_total)->toBe(3)
        ->and($vital->score_mama_level)->toBe('treat_and_refer');
});

/**
 * The third write path, and the one a filtered test run missed: completing a
 * draft with PUT /records/{id}. Three places write vitals; all three must score.
 */
it('scores the same way when the doctor completes a triage draft', function () {
    $s = makeClinicScenario();
    $record = triageDraft($s['clinic']->id);

    Sanctum::actingAs($s['nurseUser']);
    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->patchJson("/api/v1/records/{$record->id}/vitals", ['weight_kg' => 70])
        ->assertOk();

    Sanctum::actingAs($s['memUser']);
    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/records/{$record->id}", [
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis' => 'Embarazo de 34 semanas',
            'treatment' => 'Control en 2 semanas',
            'vitals' => workedExampleVitals(),
        ])
        ->assertOk();

    $vital = $record->fresh()->vitals()->latest('id')->first();

    expect($vital->score_mama_total)->toBe(3)
        ->and($vital->score_mama_level)->toBe('treat_and_refer')
        ->and($vital->score_mama_protocol)->toBe('MSP-2025');
});
