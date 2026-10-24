<?php

use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function patientHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** Valid request payload for creating a patient over the API. */
function patientPayload(array $overrides = []): array
{
    return array_merge([
        'name'             => 'Juan Pérez',
        'id_number'        => validEcuadorianCedula(),
        'document_type'    => 'cedula',
        'document_country' => 'EC',
        'birth_date'       => '1985-03-12',
        'gender'           => 'male',
        'address'          => 'Av. Amazonas N34-120 y Av. Atahualpa',
        'email'            => fake()->unique()->safeEmail(),
        'phone'            => '0991234567',
    ], $overrides);
}

// ─────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────

it('creates a patient with all required fields', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = patientPayload(['name' => 'María López']);

    $data = $this->postJson('/api/v1/patients', $payload, patientHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['name'])->toBe('María López');

    $this->assertDatabaseHas('patients', [
        'id'           => $data['id'],
        'workspace_id' => $ws->id, // injected server-side, never trusted from client
        'name'         => 'María López',
    ]);
});

it('creates a patient without an email', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = patientPayload();
    unset($payload['email']);

    $data = $this->postJson('/api/v1/patients', $payload, patientHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['email'])->toBeNull();
});

it('rejects creating a patient with a missing required field', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = patientPayload();
    unset($payload['birth_date']);

    $this->postJson('/api/v1/patients', $payload, patientHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('birth_date');
});

it('rejects creating a patient with a duplicate id_number', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $existing = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'id_number'    => '1717171717',
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients', patientPayload(['id_number' => $existing->id_number]), patientHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('id_number');
});

it('rejects creating a patient whose document belongs to a soft-deleted patient in the active workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $document = validEcuadorianCedula();
    $archivedPatient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
    $archivedPatient->delete();

    Sanctum::actingAs($user);

    $response = $this->postJson(
        '/api/v1/patients',
        patientPayload(['id_number' => $document]),
        patientHeaders($ws->id),
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id_number');

    expect($response->json('errors.id_number.0'))
        ->toContain('paciente archivado');

    $this->assertSoftDeleted('patients', ['id' => $archivedPatient->id]);
    $this->assertDatabaseCount('patients', 1);
});

it('normalizes a supplied document country to uppercase when creating a patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/patients', patientPayload([
        'id_number' => '26526641',
        'document_type' => 'foreign_id',
        'document_country' => 've',
    ]), patientHeaders($ws->id))
        ->assertCreated()
        ->json('data');

    expect($data['document_country'])->toBe('VE');

    $this->assertDatabaseHas('patients', [
        'id' => $data['id'],
        'workspace_id' => $ws->id,
        'document_type' => 'foreign_id',
        'document_country' => 'VE',
        'id_number' => '26526641',
    ]);
});

it('normalizes missing document fields and scopes duplicate documents to the active workspace', function () {
    [, $firstUser, $firstWorkspace] = makeDoctorWithWorkspace();
    [, $secondUser, $secondWorkspace] = makeDoctorWithWorkspace();
    $document = validEcuadorianCedula();
    $payload = patientPayload(['id_number' => $document]);
    unset($payload['document_type'], $payload['document_country']);

    Sanctum::actingAs($firstUser);

    $this->postJson('/api/v1/patients', $payload, patientHeaders($firstWorkspace->id))
        ->assertCreated();

    $this->postJson('/api/v1/patients', $payload, patientHeaders($firstWorkspace->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('id_number');

    Sanctum::actingAs($secondUser);

    $this->postJson('/api/v1/patients', $payload, patientHeaders($secondWorkspace->id))
        ->assertCreated();

    $this->assertDatabaseCount('patients', 2);
    $this->assertDatabaseHas('patients', [
        'workspace_id' => $firstWorkspace->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
    $this->assertDatabaseHas('patients', [
        'workspace_id' => $secondWorkspace->id,
        'id_number' => $document,
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
});

// ─────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────

it('updates a patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'phone' => '0990000000']);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/patients/{$patient->id}", [
        'phone' => '0987654321',
        'city'  => 'Guayaquil',
    ], patientHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.phone', '0987654321')
        ->assertJsonPath('data.city', 'Guayaquil');

    expect($patient->fresh()->phone)->toBe('0987654321');
});

// ─────────────────────────────────────────────
// Allergies via the scoped parent
// ─────────────────────────────────────────────

it('adds and removes an allergy through the scoped parent patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $allergyId = $this->postJson("/api/v1/patients/{$patient->id}/allergies", [
        'allergen_name' => 'Penicilina',
        'allergen_type' => 'drug',
        'severity'      => 'severe',
    ], patientHeaders($ws->id))
        ->assertStatus(201)
        ->json('id');

    $this->assertDatabaseHas('patient_allergies', [
        'id'            => $allergyId,
        'patient_id'    => $patient->id,
        'allergen_name' => 'Penicilina',
    ]);

    $this->deleteJson("/api/v1/patients/{$patient->id}/allergies/{$allergyId}", [], patientHeaders($ws->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('patient_allergies', ['id' => $allergyId]);
});

it('returns 404 when adding an allergy to a foreign-workspace patient', function () {
    [, $user, $ws]  = makeDoctorWithWorkspace();
    $foreignWs       = Workspace::factory()->create();
    $foreignPatient  = Patient::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$foreignPatient->id}/allergies", [
        'allergen_name' => 'Maní',
        'allergen_type' => 'food',
    ], patientHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseMissing('patient_allergies', ['patient_id' => $foreignPatient->id]);
});

it('returns 404 when deleting an allergy that belongs to another workspace patient', function () {
    [, $user, $ws]  = makeDoctorWithWorkspace();
    $foreignWs       = Workspace::factory()->create();
    $foreignPatient  = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignAllergy  = PatientAllergy::factory()->create(['patient_id' => $foreignPatient->id]);

    Sanctum::actingAs($user);

    // Even addressing it through our own scoped patient must not reach it.
    $ownPatient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $this->deleteJson("/api/v1/patients/{$ownPatient->id}/allergies/{$foreignAllergy->id}", [], patientHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseHas('patient_allergies', ['id' => $foreignAllergy->id]);
});
