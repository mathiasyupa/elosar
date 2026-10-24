<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\StudyOrder;
use Laravel\Sanctum\Sanctum;

/**
 * Issuing a study order for a patient, with the consultation optional.
 *
 * An order used to be reachable only through the visit that produced it, so a
 * doctor asked by phone for a repeat lab slip had to invent an empty
 * consultation — and that invented visit stayed in the chart for good.
 *
 * The order was already almost independent: it carries its own `clinical_info`,
 * and the PDF reads that first. What tied them together was a NOT NULL column
 * and routes nested under the record.
 */
function patientOrderHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function labOrderPayload(array $overrides = []): array
{
    return array_merge([
        'type' => StudyOrder::TYPE_LABORATORY,
        'priority' => 'routine',
        'clinical_info' => 'Control de hipertensión arterial. Evaluar función renal.',
        'items' => [
            ['name' => 'Creatinina', 'category' => 'Bioquímico'],
        ],
    ], $overrides);
}

it('issues an order for a patient who has no consultation at all', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson("/api/v1/patients/{$patient->id}/orders", labOrderPayload(), patientOrderHeaders($ws->id));

    $response->assertCreated();
    expect($response->json('data.medical_record_id'))->toBeNull();
    expect($response->json('data.patient_id'))->toBe($patient->id);

    $order = StudyOrder::findOrFail($response->json('data.id'));
    expect($order->workspace_id)->toBe($ws->id)
        ->and($order->items)->toHaveCount(1);
});

it('refuses an order with neither a consultation nor a clinical indication', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    // Without either, nothing on the order says why the study was requested —
    // which is what the laboratory and the insurer ask for.
    $this->postJson(
        "/api/v1/patients/{$patient->id}/orders",
        labOrderPayload(['clinical_info' => null]),
        patientOrderHeaders($ws->id),
    )->assertStatus(422)->assertJsonValidationErrors('clinical_info');

    expect(StudyOrder::where('patient_id', $patient->id)->count())->toBe(0);
});

it('lets the clinical indication be omitted when a consultation carries it', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson(
        "/api/v1/patients/{$patient->id}/orders",
        labOrderPayload(['clinical_info' => null, 'medical_record_id' => $record->id]),
        patientOrderHeaders($ws->id),
    );

    $response->assertCreated();
    expect($response->json('data.medical_record_id'))->toBe($record->id);
});

it('rechaza una consulta de otro paciente', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $someoneElse = Patient::factory()->create(['workspace_id' => $ws->id]);
    $theirRecord = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $someoneElse->id]);

    Sanctum::actingAs($user);

    // Same clinic, wrong chart: filing it would put this order — and later its
    // result — under another patient's history.
    $this->postJson(
        "/api/v1/patients/{$patient->id}/orders",
        labOrderPayload(['medical_record_id' => $theirRecord->id]),
        patientOrderHeaders($ws->id),
    )->assertStatus(422);

    expect(StudyOrder::where('patient_id', $patient->id)->count())->toBe(0);
});

it('no deja pedir exámenes para un paciente de otra clínica', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    [$otherDoctor, $otherUser, $otherWs] = makeDoctorWithWorkspace();
    $strangersPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);

    Sanctum::actingAs($user);

    $this->postJson(
        "/api/v1/patients/{$strangersPatient->id}/orders",
        labOrderPayload(),
        patientOrderHeaders($ws->id),
    )->assertNotFound();

    $this->getJson(
        "/api/v1/patients/{$strangersPatient->id}/orders",
        patientOrderHeaders($ws->id),
    )->assertNotFound();

    expect(StudyOrder::withoutGlobalScopes()->where('patient_id', $strangersPatient->id)->count())->toBe(0);
});

it('lists every order of the patient, with and without a consultation', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control de hipertensión',
    ]);

    $fromVisit = StudyOrder::create([
        'workspace_id' => $ws->id,
        'medical_record_id' => $record->id,
        'patient_id' => $patient->id,
        'type' => StudyOrder::TYPE_LABORATORY,
        'priority' => 'routine',
    ]);
    $onItsOwn = StudyOrder::create([
        'workspace_id' => $ws->id,
        'medical_record_id' => null,
        'patient_id' => $patient->id,
        'type' => StudyOrder::TYPE_LABORATORY,
        'priority' => 'routine',
        'clinical_info' => 'Control anual solicitado por teléfono.',
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson("/api/v1/patients/{$patient->id}/orders", patientOrderHeaders($ws->id));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    // The row has to be able to say which visit it came from, or "—".
    $byId = collect($response->json('data'))->keyBy('id');
    expect($byId[$fromVisit->id]['medical_record']['reason_for_consultation'])->toBe('Control de hipertensión')
        ->and($byId[$onItsOwn->id]['medical_record'])->toBeNull();
});

it('keeps the per-consultation listing working', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create(['workspace_id' => $ws->id, 'patient_id' => $patient->id]);

    Sanctum::actingAs($user);

    // The old entry point is untouched: nothing that already worked changes.
    $this->postJson("/api/v1/records/{$record->id}/orders", labOrderPayload(), patientOrderHeaders($ws->id))
        ->assertCreated();

    $this->getJson("/api/v1/records/{$record->id}/orders", patientOrderHeaders($ws->id))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
