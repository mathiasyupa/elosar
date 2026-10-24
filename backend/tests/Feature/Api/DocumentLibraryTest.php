<?php

use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordPrescription;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Workspace-wide & patient document library (GET /documents & POST /patients/{id}/documents).
*/

it('lists the active workspace attachments with patient + record context', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Doc']);
    $record = MedicalRecord::factory()->forWorkspace($ws, $patient, $doctor)->create();

    MedicalAttachment::create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'medical_record_id' => $record->id,
        'title' => 'hemograma.pdf',
        'file_path' => 'demo/hemograma.pdf',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/documents', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'hemograma.pdf')
        ->assertJsonPath('data.0.patient.name', 'Paciente Doc')
        ->assertJsonPath('data.0.record_id', $record->id);

    $this->assertDatabaseHas('access_logs', [
        'user_id' => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'document_library',
        'subject_id' => $ws->id,
        'action' => 'list',
    ]);
});

it('includes issued prescriptions in the document library', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Receta']);
    $record = MedicalRecord::factory()->forWorkspace($ws, $patient, $doctor)->create();

    // Through the factory: building the row by hand skipped `frequency`, which
    // is NOT NULL, so this test failed on its own arrange step.
    RecordPrescription::factory()->create([
        'medical_record_id' => $record->id,
        'medication_name' => 'Amoxicilina 500mg',
        'dosage' => '1 cada 8 horas',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/documents', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.source', 'prescription')
        ->assertJsonPath('data.0.type', 'prescription')
        ->assertJsonPath('data.0.patient.name', 'Paciente Receta');
});

it('allows uploading a document directly to a patient without a consultation', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Directo']);

    Sanctum::actingAs($user);

    $file = UploadedFile::fake()->create('examen_externo.pdf', 100, 'application/pdf');

    $this->postJson("/api/v1/patients/{$patient->id}/documents", [
        'file' => $file,
        'title' => 'Examen de laboratorio externo',
        'notes' => 'Traído por el paciente',
    ], ['X-Workspace-Id' => (string) $ws->id])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Examen de laboratorio externo')
        ->assertJsonPath('data.record_id', null);

    $this->getJson("/api/v1/patients/{$patient->id}/documents", ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Examen de laboratorio externo');

    $this->assertDatabaseHas('access_logs', [
        'user_id' => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'patient',
        'subject_id' => $patient->id,
        'action' => 'documents_list',
    ]);
});

it('downloads a direct patient attachment and records the clinical access', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$patient->id}/documents", [
        'file' => UploadedFile::fake()->create('resultado.pdf', 100, 'application/pdf'),
    ], ['X-Workspace-Id' => (string) $ws->id])
        ->assertCreated();

    $attachment = MedicalAttachment::query()->sole();

    $this->get("/api/v1/attachments/{$attachment->id}/download", ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertDownload('resultado.pdf');

    $this->assertDatabaseHas('access_logs', [
        'user_id' => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'medical_attachment',
        'subject_id' => $attachment->id,
        'action' => 'download',
    ]);
});

it('rejects a direct attachment that points at a foreign medical record', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    [$foreignDoctor, , $foreignWs] = makeDoctorWithWorkspace();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignRecord = MedicalRecord::factory()->forWorkspace($foreignWs, $foreignPatient, $foreignDoctor)->create();
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$patient->id}/documents", [
        'file' => UploadedFile::fake()->create('ajeno.pdf', 100, 'application/pdf'),
        'medical_record_id' => $foreignRecord->id,
    ], ['X-Workspace-Id' => (string) $ws->id])
        ->assertNotFound();

    expect(MedicalAttachment::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

it('never leaks attachments from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    // Foreign workspace with its own record + attachment.
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignRecord = MedicalRecord::factory()->forWorkspace($foreignWs, $foreignPatient)->create();
    MedicalAttachment::create([
        'workspace_id' => $foreignWs->id,
        'patient_id' => $foreignPatient->id,
        'medical_record_id' => $foreignRecord->id,
        'title' => 'ajeno.pdf',
        'file_path' => 'demo/ajeno.pdf',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/documents', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('stores the type chosen at upload and lists it, with the file extension', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->post('/api/v1/patients/'.$patient->id.'/documents', [
        'file' => UploadedFile::fake()->create('resultado.pdf', 20, 'application/pdf'),
        'category' => 'lab',
    ], ['X-Workspace-Id' => (string) $ws->id])
        ->assertCreated()
        ->assertJsonPath('data.type', 'lab')
        ->assertJsonPath('data.category', 'lab')
        ->assertJsonPath('data.extension', 'pdf');

    $this->getJson('/api/v1/documents', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.0.type', 'lab');
});

it('rejects a document type that does not exist', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->post('/api/v1/patients/'.$patient->id.'/documents', [
        'file' => UploadedFile::fake()->create('x.pdf', 20, 'application/pdf'),
        'category' => 'receta-falsa',
    ], ['X-Workspace-Id' => (string) $ws->id, 'Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('category');
});

it('titles a study order with the studies it asks for', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $order = App\Models\StudyOrder::create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'type' => 'laboratory',
        'priority' => 'routine',
    ]);
    $order->items()->create(['name' => 'Biometría hemática', 'category' => 'Hematología']);
    Sanctum::actingAs($user);

    $titles = collect($this->getJson('/api/v1/documents', ['X-Workspace-Id' => (string) $ws->id])->json('data'))->pluck('title');

    expect($titles->first(fn ($t) => str_starts_with($t, 'Orden de')))->toContain('Biometría hemática');
});
