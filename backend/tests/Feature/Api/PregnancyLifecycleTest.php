<?php

use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\Specialty;
use App\Services\Obstetrics\GestationalAge;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Pregnancy — open, correct the dating, close, reopen
|--------------------------------------------------------------------------
| The pregnancy is an entity with a lifecycle, not a block on the patient card
| that the next one overwrites. What the server guarantees: it computes the due
| date itself, it refuses a second active pregnancy, and closing one frees the
| patient for the next.
*/

function pregnancyPatient(int $workspaceId): Patient
{
    return Patient::factory()->create(['workspace_id' => $workspaceId]);
}

function pregnancyLifecycleGynecoScenario(): array
{
    $scenario = makeClinicScenario();
    $specialty = Specialty::query()->firstOrCreate(['name' => 'Ginecología y Obstetricia']);
    $specialty->capabilities = [Specialty::CAPABILITY_GYNECO_OBSTETRICS];
    $specialty->save();

    $scenario['ownerDoctor']->specialties()->syncWithoutDetaching([$specialty->id => ['is_primary' => false]]);

    return $scenario;
}

it('computes and stores the due date instead of trusting the client', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $response = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/pregnancies", [
            'lmp_date'      => '2026-01-01',
            'dating_source' => 'lmp',
            // A stale or malicious client sending its own due date.
            'edd_date'      => '2030-01-01',
            'edd_protocol'  => 'MADE-UP',
        ])
        ->assertCreated();

    $pregnancy = Pregnancy::findOrFail($response->json('data.id'));

    expect($pregnancy->edd_date->toDateString())->toBe('2026-10-08')
        ->and($pregnancy->edd_protocol)->toBe(GestationalAge::PROTOCOL);
});

/**
 * Names the layer that actually provides the guarantee above.
 *
 * The test before this one passes even with the controller's defensive `unset()`
 * removed — because the FormRequest allow-list never lets the derived columns
 * reach `validated()` in the first place. That was worth finding out: a test that
 * passes for a reason other than the one in its name will keep passing after the
 * protection it claims to cover is gone.
 *
 * So the allow-list is asserted directly. If someone adds `edd_date` to the rules,
 * this goes red and points at the controller's `unset()` as the remaining defence.
 */
it('keeps the derived due-date columns out of the request allow-list', function () {
    $store = array_keys((new App\Http\Requests\StorePregnancyRequest)->rules());
    $update = array_keys((new App\Http\Requests\UpdatePregnancyRequest)->rules());

    expect($store)->not->toContain('edd_date')
        ->and($store)->not->toContain('edd_protocol')
        ->and($update)->not->toContain('edd_date')
        ->and($update)->not->toContain('edd_protocol');
});

it('refuses to open a second pregnancy while one is still active', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/pregnancies", [
            'lmp_date' => now()->subDays(30)->toDateString(),
        ])
        ->assertStatus(422);

    expect(Pregnancy::where('patient_id', $patient->id)->count())->toBe(1);
});

/**
 * The controller check is a courtesy so the doctor reads Spanish instead of a
 * 500. The guarantee is the partial unique index, which is what makes two open
 * tabs safe — so it is tested without going through the controller at all.
 */
it('lets the database refuse a second active pregnancy, not just the controller', function () {
    $s = pregnancyLifecycleGynecoScenario();
    $patient = pregnancyPatient($s['clinic']->id);

    Pregnancy::factory()->forPatient($patient)->create();

    expect(fn () => Pregnancy::factory()->forPatient($patient)->create())
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('frees the patient for a new pregnancy once the previous one is closed', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $first = Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/pregnancies/{$first->id}/close", [
            'outcome'      => 'term_birth',
            'outcome_date' => now()->toDateString(),
        ])
        ->assertOk();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/pregnancies", [
            'lmp_date' => now()->subDays(30)->toDateString(),
        ])
        ->assertCreated();

    expect(Pregnancy::where('patient_id', $patient->id)->count())->toBe(2)
        ->and($first->fresh()->status)->toBe('completed')
        ->and($first->fresh()->closed_by_user_id)->toBe($s['ownerUser']->id);
});

/**
 * Soft-deleting and reopening is the case the partial index gets wrong when the
 * `deleted_at IS NULL` clause is missing — and it is the kind of thing that only
 * shows up in production, on the day someone fixes a mistyped pregnancy.
 */
it('lets a patient get a new pregnancy after a mistaken one is deleted', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $mistake = Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->deleteJson("/api/v1/pregnancies/{$mistake->id}")
        ->assertNoContent();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/pregnancies", [
            'lmp_date' => now()->subDays(20)->toDateString(),
        ])
        ->assertCreated();
});

it('re-derives the due date when the clinician switches to the scan', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $pregnancy = Pregnancy::factory()->forPatient($patient)->create([
        'lmp_date'         => '2026-01-01',
        'ultrasound_date'  => '2026-03-01',
        'ultrasound_weeks' => 10,
        'ultrasound_days'  => 0,
        'dating_source'    => 'lmp',
        ...GestationalAge::columnsFor(['lmp_date' => '2026-01-01', 'dating_source' => 'lmp']),
    ]);

    expect($pregnancy->edd_date->toDateString())->toBe('2026-10-08');

    // Only the source changes; the dates already on the row must still be used.
    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/pregnancies/{$pregnancy->id}", ['dating_source' => 'ultrasound'])
        ->assertOk();

    expect($pregnancy->fresh()->edd_date->toDateString())->toBe('2026-09-27');
});

it('reports both datings and their discrepancy without choosing one', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $pregnancy = Pregnancy::factory()->forPatient($patient)->create([
        'lmp_date'         => '2026-01-01',
        'ultrasound_date'  => '2026-03-01',
        'ultrasound_weeks' => 10,
        'ultrasound_days'  => 0,
        'dating_source'    => 'lmp',
    ]);

    $dating = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}")
        ->assertOk()
        ->json('data.dating');

    expect($dating['edd_by_lmp'])->toBe('2026-10-08')
        ->and($dating['edd_by_ultrasound'])->toBe('2026-09-27')
        ->and($dating['discrepancy_days'])->toBe(11)
        ->and($dating['source_used'])->toBe('lmp');
});

it('refuses a pregnancy nobody can date', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/patients/{$patient->id}/pregnancies", ['fetus_count' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('lmp_date');
});

it('refuses to delete a pregnancy that already has consultations', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    App\Models\MedicalRecord::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id'   => $patient->id,
        'pregnancy_id' => $pregnancy->id,
    ]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->deleteJson("/api/v1/pregnancies/{$pregnancy->id}")
        ->assertStatus(422);

    expect($pregnancy->fresh())->not->toBeNull();
});

it('returns 404 for a pregnancy of another clinic', function () {
    $mine = pregnancyLifecycleGynecoScenario();
    $theirs = pregnancyLifecycleGynecoScenario();

    $otherPatient = pregnancyPatient($theirs['clinic']->id);
    $otherPregnancy = Pregnancy::factory()->forPatient($otherPatient)->create();

    Sanctum::actingAs($mine['ownerUser']);

    $this->withHeader('X-Workspace-Id', (string) $mine['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$otherPregnancy->id}")
        ->assertNotFound();
});

it('does not let the front desk read the pregnancy list', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['recUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/patients/{$patient->id}/pregnancies")
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Tamizajes del control prenatal (carné perinatal)
|--------------------------------------------------------------------------
| The whole MSP schedule comes back, not just what is recorded: the point of a
| perinatal card is seeing what is still MISSING.
*/

it('returns the whole MSP schedule, marking what is recorded and what is universal', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/pregnancies/{$pregnancy->id}/screenings", [
            'code'      => 'hemoglobin',
            'result'    => '11.2 g/dL',
            'result_at' => now()->subDays(3)->toDateString(),
        ])
        ->assertCreated();

    $items = collect($this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}/screenings")
        ->assertOk()
        ->json('data'));

    $hb = $items->firstWhere('code', 'hemoglobin');
    $strep = $items->firstWhere('code', 'group_b_strep');

    expect($items->count())->toBeGreaterThan(15)
        ->and($hb['recorded'])->toBeTrue()
        ->and($hb['result'])->toBe('11.2 g/dL')
        // Not yet done, but still listed: that is the point of the card.
        ->and($strep['recorded'])->toBeFalse()
        // And the MSP asks for it only on a risk factor, so an empty box here is
        // not an omission — the screen has to be able to say so.
        ->and($strep['universal'])->toBeFalse()
        ->and($items->firstWhere('code', 'hemoglobin')['universal'])->toBeTrue();
});

it('corrects a screening instead of stacking a second row', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    foreach (['negativo', 'positivo'] as $result) {
        $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
            ->postJson("/api/v1/pregnancies/{$pregnancy->id}/screenings", [
                'code'   => 'vdrl',
                'result' => $result,
            ])
            ->assertCreated();
    }

    expect($pregnancy->screenings()->where('code', 'vdrl')->count())->toBe(1)
        ->and($pregnancy->screenings()->where('code', 'vdrl')->first()->result)->toBe('positivo');
});

/**
 * The catalogue is the MSP schedule, not free text. A typo must not create a
 * screening that exists nowhere in the norm and then sits on the card looking
 * official.
 */
it('rejects a screening that is not in the MSP schedule', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson("/api/v1/pregnancies/{$pregnancy->id}/screenings", ['code' => 'horoscopo'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

/*
|--------------------------------------------------------------------------
| El control prenatal se enlaza solo con el embarazo
|--------------------------------------------------------------------------
| `medical_records.pregnancy_id` existía sin que nada lo escribiera. Sin él, la
| línea de tiempo del embarazo no tiene de dónde salir.
*/

it('links a prenatal visit to the active pregnancy without asking', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    $id = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control en 4 semanas',
            'consultation_type'       => 'prenatal',
        ])
        ->assertCreated()
        ->json('data.id');

    expect(App\Models\MedicalRecord::findOrFail($id)->pregnancy_id)->toBe($pregnancy->id);
});

/**
 * Una consulta ginecológica durante un embarazo NO es un control prenatal;
 * ponerla en la línea de seguimiento tergiversaría el registro.
 */
it('does not put a gynecological consultation on the pregnancy timeline', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    Pregnancy::factory()->forPatient($patient)->create();

    $id = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Molestia',
            'diagnosis'               => 'Vaginosis',
            'treatment'               => 'Óvulos',
            'consultation_type'       => 'gynecological',
        ])
        ->assertCreated()
        ->json('data.id');

    expect(App\Models\MedicalRecord::findOrFail($id)->pregnancy_id)->toBeNull();
});

it('leaves the link null when the patient has no pregnancy on file', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);

    $id = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control',
            'consultation_type'       => 'prenatal',
        ])
        ->assertCreated()
        ->json('data.id');

    expect(App\Models\MedicalRecord::findOrFail($id)->pregnancy_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| La curva del embarazo
|--------------------------------------------------------------------------
| Un punto por control prenatal, leído de donde ya se escribió: la plantilla
| prenatal y los signos vitales de esa visita. Sin segunda tabla.
*/

it('builds the curve from the linked prenatal visits', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $lmp = now()->subWeeks(28)->toDateString();
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create([
        'lmp_date'      => $lmp,
        'dating_source' => 'lmp',
        ...GestationalAge::columnsFor(['lmp_date' => $lmp, 'dating_source' => 'lmp']),
    ]);

    $record = App\Models\MedicalRecord::factory()->create([
        'workspace_id'      => $s['clinic']->id,
        'patient_id'        => $patient->id,
        'pregnancy_id'      => $pregnancy->id,
        'consultation_type' => 'prenatal',
        // El texto libre de la plantilla: "28 cm" tiene que graficar en 28.
        'structured_exam'   => ['altura_uterina' => '28 cm', 'fcf' => '140'],
    ]);
    $record->vitals()->create(['weight_kg' => 68.5, 'blood_pressure_sys' => 132, 'blood_pressure_dia' => 86, 'recorded_at' => now()]);

    $points = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}/timeline")
        ->assertOk()
        ->json('data');

    // `toEqual` y no `(float) ... toBe`: el JSON devuelve 28 y no 28.0 cuando el
    // decimal es exacto, pero castear a float convierte un **null en 0.0** y
    // entonces el test pasa con el dato ausente. Esta versión lo cazó: `weight`
    // leía una columna que no existe (`weight` en vez de `weight_kg`) y llegaba
    // nulo, disfrazado de cero por el propio cast del test.
    expect($points)->toHaveCount(1)
        ->and($points[0]['fundal_height'])->toEqual(28)
        ->and($points[0]['fetal_heart_rate'])->toEqual(140)
        ->and($points[0]['weight'])->toEqual(68.5)
        ->and($points[0]['weight'])->not->toBeNull()
        // Blood pressure per control: what is watched for preeclampsia.
        ->and($points[0]['bp_systolic'])->toBe(132)
        ->and($points[0]['bp_diastolic'])->toBe(86)
        ->and($points[0]['weeks'])->toBe(28);
});

/**
 * Si el médico redata por ecografía, la curva entera se mueve con la corrección
 * en vez de quedarse con semanas que ya nadie se cree.
 */
it('re-dates the whole curve when the dating changes', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $lmp = now()->subWeeks(28)->toDateString();
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create([
        'lmp_date'         => $lmp,
        'ultrasound_date'  => now()->subWeeks(14)->toDateString(),
        'ultrasound_weeks' => 12,
        'dating_source'    => 'lmp',
        ...GestationalAge::columnsFor(['lmp_date' => $lmp, 'dating_source' => 'lmp']),
    ]);

    App\Models\MedicalRecord::factory()->create([
        'workspace_id'      => $s['clinic']->id,
        'patient_id'        => $patient->id,
        'pregnancy_id'      => $pregnancy->id,
        'consultation_type' => 'prenatal',
        'structured_exam'   => ['altura_uterina' => '28'],
    ]);

    $before = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}/timeline")->json('data.0.weeks');

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/pregnancies/{$pregnancy->id}", ['dating_source' => 'ultrasound'])
        ->assertOk();

    $after = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}/timeline")->json('data.0.weeks');

    expect($before)->toBe(28)->and($after)->toBe(26);
});

it('leaves an unmeasured visit on the timeline without a fundal height', function () {
    $s = pregnancyLifecycleGynecoScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = pregnancyPatient($s['clinic']->id);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();

    App\Models\MedicalRecord::factory()->create([
        'workspace_id'      => $s['clinic']->id,
        'patient_id'        => $patient->id,
        'pregnancy_id'      => $pregnancy->id,
        'consultation_type' => 'prenatal',
        'structured_exam'   => ['observaciones_prenatal' => 'Sin novedad'],
    ]);

    $points = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->getJson("/api/v1/pregnancies/{$pregnancy->id}/timeline")
        ->assertOk()
        ->json('data');

    // El control existió: no se borra del seguimiento por no haber medido.
    expect($points)->toHaveCount(1)
        ->and($points[0]['fundal_height'])->toBeNull();
});
