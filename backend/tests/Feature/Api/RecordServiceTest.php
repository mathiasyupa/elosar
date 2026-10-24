<?php

use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\RecordService;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/**
 * Services and procedures performed, declared while attending so the cash desk
 * invoices what actually happened.
 *
 * The rule worth guarding is who may put a price on them: a solo doctor owns
 * their consultorio and holds billing.manage, while the `doctor` role inside a
 * clinic holds only billing.view. The screen hides the price field for the
 * second — these tests exist because hiding a field is not enforcement.
 */
function serviceHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('records what was performed, with its price, for a solo consultorio', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $arancel = MedicalService::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Biometría hemática',
        'category' => 'laboratory',
        'base_price' => '17.50',
    ]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
        'services' => [[
            'medical_service_id' => $arancel->id,
            'name' => 'Biometría hemática',
            'category' => 'laboratory',
            'quantity' => 2,
            'unit_price' => '17.50',
        ]],
    ], serviceHeaders($ws->id))->assertStatus(201)->json('data');

    expect($data['services'])->toHaveCount(1)
        ->and($data['services'][0]['name'])->toBe('Biometría hemática')
        ->and($data['services'][0]['category'])->toBe('laboratory')
        ->and($data['services'][0]['unit_price'])->toBe('17.50')
        // bcmath, not floats: 17.50 × 2 must be exact.
        ->and($data['services'][0]['line_total'])->toBe('35.00');

    $this->assertDatabaseHas('record_services', [
        'medical_record_id' => $data['id'],
        'workspace_id' => $ws->id,
        'medical_service_id' => $arancel->id,
        'category' => 'laboratory',
    ]);
});

it('refuses to let a clinic doctor put a price on their own work', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    // The member doctor holds billing.view but not billing.manage.
    Sanctum::actingAs($s['memUser']);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
        'services' => [[
            'name' => 'Curación',
            'category' => 'procedure',
            'quantity' => 1,
            // Crafted by hand — the screen never shows this field to them.
            'unit_price' => '5.00',
        ]],
    ], serviceHeaders($clinic->id))->assertStatus(201)->json('data');

    // The service is recorded; the price is not. Caja decides what it costs.
    expect($data['services'])->toHaveCount(1)
        ->and($data['services'][0]['name'])->toBe('Curación')
        ->and($data['services'][0]['unit_price'])->toBeNull()
        ->and($data['services'][0]['line_total'])->toBeNull();

    $this->assertDatabaseHas('record_services', [
        'medical_record_id' => $data['id'],
        'name' => 'Curación',
        'unit_price' => null,
    ]);
});

it('hands the cash desk the services without handing over the clinical record', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    Sanctum::actingAs($s['memUser']);
    $recordId = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Dolor lumbar',
        'diagnosis' => 'Lumbalgia mecánica',
        'treatment' => 'Reposo',
        'services' => [['name' => 'Curación', 'category' => 'procedure', 'quantity' => 1]],
    ], serviceHeaders($clinic->id))->assertStatus(201)->json('data.id');

    Sanctum::actingAs($s['recUser']);

    // Reception holds billing.manage but NOT records.view, and must not: the
    // clinical record stays out of reach (LOPDP).
    $this->getJson("/api/v1/records/{$recordId}", serviceHeaders($clinic->id))
        ->assertStatus(403);

    // The services still reach them, through the billing surface — what to
    // charge, never the diagnosis.
    $billable = $this->getJson('/api/v1/clinic/billables', serviceHeaders($clinic->id))
        ->assertOk()
        ->json('data.0');

    expect($billable['services'])->toHaveCount(1)
        ->and($billable['services'][0]['name'])->toBe('Curación')
        ->and($billable['services'][0]['unit_price'])->toBeNull()
        ->and(json_encode($billable))->not->toContain('Lumbalgia');
});

it('keeps services of one clinic invisible to another', function () {
    [$doctorA, $userA, $wsA] = makeDoctorWithWorkspace();
    [$doctorB, $userB, $wsB] = makeDoctorWithWorkspace();

    $patientA = Patient::factory()->create(['workspace_id' => $wsA->id]);

    Sanctum::actingAs($userA);
    $recordId = $this->postJson('/api/v1/records', [
        'patient_id' => $patientA->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
        'services' => [['name' => 'Ecografía', 'category' => 'imaging', 'unit_price' => '30.00']],
    ], serviceHeaders($wsA->id))->assertStatus(201)->json('data.id');

    // The other doctor cannot reach the record at all.
    Sanctum::actingAs($userB);
    $this->getJson("/api/v1/records/{$recordId}", serviceHeaders($wsB->id))
        ->assertStatus(404);

    // And the model's own scope hides the row even when queried directly —
    // this one carries workspace_id precisely so it does not depend on the
    // parent always being loaded through a scoped query. bind() is the
    // stateless setter; set() would first check the session user's access.
    app(App\Support\ActiveWorkspace::class)->bind($wsB->id);
    expect(RecordService::query()->count())->toBe(0);

    app(App\Support\ActiveWorkspace::class)->bind($wsA->id);
    expect(RecordService::query()->count())->toBe(1);
});

it('replaces the declared services when the visit is completed from a draft', function () {
    $s = makeClinicScenario();
    $clinic = $s['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);

    $draft = MedicalRecord::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'status' => 'draft',
    ]);
    $draft->services()->create([
        'workspace_id' => $clinic->id,
        'name' => 'Servicio equivocado',
        'quantity' => 1,
    ]);

    Sanctum::actingAs($s['memUser']);

    $data = $this->putJson("/api/v1/records/{$draft->id}", [
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
        'services' => [['name' => 'Curación', 'quantity' => 1]],
    ], serviceHeaders($clinic->id))->assertOk()->json('data');

    expect($data['services'])->toHaveCount(1)
        ->and($data['services'][0]['name'])->toBe('Curación');
});

it('leaves the record alone when no services are declared', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
    ], serviceHeaders($ws->id))->assertStatus(201)->json('data');

    expect($data['services'])->toHaveCount(0);
});
