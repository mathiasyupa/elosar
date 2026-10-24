<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Study;
use App\Models\StudyOrder;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/** Workspace header helper local to this file (avoids cross-file collisions). */
function studyHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A completed consultation owned by the given workspace/doctor. */
function makeRecord(Workspace $ws, int $patientId, int $doctorId): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patientId,
        'created_by'   => $doctorId,
    ]);
}

// ─────────────────────────────────────────────
// Catalog
// ─────────────────────────────────────────────

it('returns the lab catalog (global + workspace custom) filtered by type', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    Study::factory()->create(['workspace_id' => null, 'type' => 'laboratory', 'category' => 'Hematológico', 'name' => 'Biometría hemática completa']);
    Study::factory()->imaging()->create(['workspace_id' => null, 'name' => 'Radiografía de tórax']);
    Study::factory()->create(['workspace_id' => $ws->id, 'type' => 'laboratory', 'category' => 'Clínica', 'name' => 'Panel interno de la clínica']);
    // Another workspace's custom study must NOT leak into this catalog.
    $foreign = Workspace::factory()->create();
    Study::factory()->create(['workspace_id' => $foreign->id, 'type' => 'laboratory', 'name' => 'Examen ajeno']);

    Sanctum::actingAs($user);

    $names = $this->getJson('/api/v1/studies?type=laboratory', studyHeaders($ws->id))
        ->assertOk()
        ->json('data.*.name');

    expect($names)->toContain('Biometría hemática completa')
        ->toContain('Panel interno de la clínica')
        ->not->toContain('Radiografía de tórax')   // filtered out by type
        ->not->toContain('Examen ajeno');           // other workspace
});

it('lets a workspace add and remove a custom study', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/studies', [
        'type' => 'imaging', 'category' => 'Ecografía', 'name' => 'Ecografía Doppler renal',
    ], studyHeaders($ws->id))->assertStatus(201)->json('data.id');

    $this->assertDatabaseHas('studies', ['id' => $id, 'workspace_id' => $ws->id, 'name' => 'Ecografía Doppler renal']);

    $this->deleteJson("/api/v1/studies/{$id}", [], studyHeaders($ws->id))->assertNoContent();
    $this->assertDatabaseMissing('studies', ['id' => $id]);
});

// ─────────────────────────────────────────────
// Create orders
// ─────────────────────────────────────────────

it('creates a laboratory order with items against a consultation', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = makeRecord($ws, $patient->id, $doctor->id);
    $study = Study::factory()->create(['workspace_id' => null, 'type' => 'laboratory', 'category' => 'Hematológico', 'name' => 'Biometría hemática completa']);

    Sanctum::actingAs($user);

    $data = $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'               => 'laboratory',
        'priority'           => 'urgent',
        'clinical_info'      => 'Anemia en estudio',
        'notes'              => 'Ayuno de 8 horas',
        'to_be_performed_at' => '2026-07-01',
        'items' => [
            ['study_id' => $study->id, 'name' => 'lo que el cliente envíe se ignora si hay catálogo'],
            ['name' => 'Ferritina sérica', 'category' => 'Química sanguínea'],
        ],
    ], studyHeaders($ws->id))->assertStatus(201)->json('data');

    // Header injected server-side.
    $this->assertDatabaseHas('study_orders', [
        'id'           => $data['id'],
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_by'   => $doctor->id,
        'type'         => 'laboratory',
        'priority'     => 'urgent',
    ]);

    // Two items; the catalog-linked one snapshots the canonical name.
    expect($data['items'])->toHaveCount(2)
        ->and(collect($data['items'])->pluck('name'))->toContain('Biometría hemática completa')
        ->toContain('Ferritina sérica');

    $this->assertDatabaseHas('study_order_items', [
        'study_order_id' => $data['id'],
        'study_id'       => $study->id,
        'name'           => 'Biometría hemática completa',
    ]);
});

it('creates an imaging order and renders the PDF', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = makeRecord($ws, $patient->id, $doctor->id);

    Sanctum::actingAs($user);

    $orderId = $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'  => 'imaging',
        'items' => [['name' => 'Ecografía abdominal', 'category' => 'Ecografía']],
    ], studyHeaders($ws->id))->assertStatus(201)->json('data.id');

    $this->get("/api/v1/study-orders/{$orderId}/pdf", studyHeaders($ws->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('exposes orders inside the consultation detail', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = makeRecord($ws, $patient->id, $doctor->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'  => 'laboratory',
        'items' => [['name' => 'Glucosa basal', 'category' => 'Química sanguínea']],
    ], studyHeaders($ws->id))->assertStatus(201);

    $this->getJson("/api/v1/records/{$record->id}", studyHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.study_orders.0.type', 'laboratory')
        ->assertJsonPath('data.study_orders.0.items.0.name', 'Glucosa basal');
});

// ─────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────

it('rejects an order with no items or an invalid type', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = makeRecord($ws, $patient->id, $doctor->id);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'  => 'genetics', // not allowed
        'items' => [],
    ], studyHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['type', 'items']);
});

// ─────────────────────────────────────────────
// Isolation
// ─────────────────────────────────────────────

it('returns 404 when ordering against a foreign-workspace record', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignRecord = MedicalRecord::factory()->create([
        'workspace_id' => $foreignWs->id,
        'patient_id'   => $foreignPatient->id,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$foreignRecord->id}/orders", [
        'type'  => 'laboratory',
        'items' => [['name' => 'Glucosa']],
    ], studyHeaders($ws->id))->assertNotFound();

    $this->assertDatabaseMissing('study_orders', ['medical_record_id' => $foreignRecord->id]);
});

it('returns 404 reading a foreign-workspace order', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignOrder = StudyOrder::factory()->create();

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/study-orders/{$foreignOrder->id}", studyHeaders($ws->id))->assertNotFound();
    $this->get("/api/v1/study-orders/{$foreignOrder->id}/pdf", studyHeaders($ws->id))->assertNotFound();
});

it('nulls a study_id that belongs to another workspace but keeps the snapshot name', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = makeRecord($ws, $patient->id, $doctor->id);

    $foreignWs = Workspace::factory()->create();
    $foreignStudy = Study::factory()->create(['workspace_id' => $foreignWs->id, 'type' => 'laboratory', 'name' => 'Examen privado ajeno']);

    Sanctum::actingAs($user);

    $orderId = $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'  => 'laboratory',
        'items' => [['study_id' => $foreignStudy->id, 'name' => 'Examen solicitado']],
    ], studyHeaders($ws->id))->assertStatus(201)->json('data.id');

    // study_id dropped (cross-workspace ref), but the requested name is preserved.
    $this->assertDatabaseHas('study_order_items', [
        'study_order_id' => $orderId,
        'study_id'       => null,
        'name'           => 'Examen solicitado',
    ]);
});

// ─────────────────────────────────────────────
// Permissions
// ─────────────────────────────────────────────

it('forbids a nurse (no records.manage) from issuing an order', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $clinic->id,
        'patient_id'   => $patient->id,
        'created_by'   => $scenario['memDoctor']->id,
    ]);

    Sanctum::actingAs($scenario['nurseUser']);

    $this->postJson("/api/v1/records/{$record->id}/orders", [
        'type'  => 'laboratory',
        'items' => [['name' => 'Glucosa basal']],
    ], studyHeaders($clinic->id))->assertForbidden();
});
