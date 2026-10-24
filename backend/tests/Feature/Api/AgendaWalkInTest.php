<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| Bloque 3: walk-in appointments and LODP PDF download logging.
*/

function agendaHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('creates a walk-in appointment checked in with an existing patient', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    Sanctum::actingAs($s['recUser']);

    $data = $this->postJson('/api/v1/appointments/walk-in', [
        'patient_id' => $patient->id,
        'doctor_id' => $s['memDoctor']->id,
    ], agendaHeaders($s['clinic']->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['status'])->toBe('checked_in')
        ->and($data['type'])->toBe('walk_in')
        ->and($data['doctor_id'])->toBe($s['memDoctor']->id);

    $this->assertDatabaseHas('appointments', [
        'patient_id' => $patient->id,
        'status' => 'checked_in',
        'type' => 'walk_in',
    ]);
});

it('creates a walk-in with minimal new patient data', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['recUser']);

    $data = $this->postJson('/api/v1/appointments/walk-in', [
        'name' => 'Paciente Walk-In',
        'id_number' => '1799999999',
        'doctor_id' => $s['memDoctor']->id,
    ], agendaHeaders($s['clinic']->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['status'])->toBe('checked_in')
        ->and($data['patient']['name'])->toBe('Paciente Walk-In');

    // Nothing is invented: a minimal walk-in leaves demographics/contact empty
    // ("sin dato") instead of writing a fake birth date, gender, city, etc.
    $this->assertDatabaseHas('patients', [
        'id_number' => '1799999999',
        'birth_date' => null,
        'gender' => null,
        'city' => null,
        'phone' => null,
        'email' => null,
    ]);
});

it('logs access when downloading a prescription pdf', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'created_by' => $s['memDoctor']->id,
    ]);
    Sanctum::actingAs($s['memUser']);

    $this->get("/api/v1/records/{$record->id}/prescription", agendaHeaders($s['clinic']->id))
        ->assertOk();

    $this->assertDatabaseHas('access_logs', [
        'user_id' => $s['memUser']->id,
        'workspace_id' => $s['clinic']->id,
        'subject_type' => 'medical_record',
        'subject_id' => $record->id,
        'action' => 'prescription_pdf',
    ]);
});

it('exposes historical provenance and refuses a new PDF for an imported record', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'created_by' => $s['memDoctor']->id,
        'status' => 'completed',
        'import_source' => 'orpheus',
        'treatment' => 'Historical text from a fictional legacy record',
    ]);
    Sanctum::actingAs($s['memUser']);

    $this->getJson("/api/v1/records/{$record->id}", agendaHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data.import_source', 'orpheus');

    $this->get("/api/v1/records/{$record->id}/prescription", agendaHeaders($s['clinic']->id))
        ->assertNotFound();
});
