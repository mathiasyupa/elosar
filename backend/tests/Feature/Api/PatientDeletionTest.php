<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Deleting a patient means one of two different things, and picking the wrong one
 * is not a cosmetic bug: a patient typed by mistake should really go, and a
 * patient with a history carries a ten-year retention duty that belongs to the
 * professional. These tests pin which is which.
 */
function deletionHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/**
 * Reads the row straight from the table.
 *
 * Eloquent is the wrong instrument here: WorkspaceScope would hide another
 * clinic's patient, and a test that asserts "it is still there" must not be
 * satisfied by a scope that merely refuses to look.
 */
function patientRow(int $id): ?object
{
    return DB::table('patients')->where('id', $id)->first();
}

it('really deletes a patient that has nothing attached', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'deleted');

    // Gone for real, not hidden: a mistyped row kept forever is just a list that
    // grows with things nobody wants.
    expect(patientRow($patient->id))->toBeNull();
})->group('security');

it('archives instead of deleting when the patient has a clinical history', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_by'   => $doctor->id,
    ]);

    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    // Out of the list…
    expect(Patient::find($patient->id))->toBeNull();
    // …but not out of the database, and the record it belongs to is untouched.
    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
    $this->assertDatabaseHas('medical_records', ['id' => $record->id, 'deleted_at' => null]);
})->group('security');

it('archives instead of deleting when the only consultation was already archived', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_by'   => $doctor->id,
    ]);
    $record->delete();

    // Soft-deleting a consultation is not permission to erase the patient and
    // cascade the consultation out of the database.
    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
    $this->assertSoftDeleted('medical_records', ['id' => $record->id]);
})->group('security');

it('archives instead of deleting when the patient has a documented allergy', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    \App\Models\PatientAllergy::factory()->create(['patient_id' => $patient->id]);

    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
})->group('security');

it('archives instead of deleting when the patient has family health history', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    \App\Models\FamilyBackground::factory()->create(['patient_id' => $patient->id]);

    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
})->group('security');

it('archives instead of deleting when the patient has a direct clinical attachment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    \App\Models\MedicalAttachment::query()->create([
        'workspace_id'       => $ws->id,
        'patient_id'         => $patient->id,
        'medical_record_id'  => null,
        'title'              => 'Radiograf\u00eda',
        'file_path'          => 'attachments/radiografia.pdf',
        'notes'              => 'Adjunto cl\u00ednico directo.',
    ]);

    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
})->group('security');

it('archives a patient whose only trace is an appointment', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    \App\Models\Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'doctor_id'    => $doctor->id,
    ]);

    // No consultation yet, but deleting for real would leave the agenda pointing
    // at nobody.
    $this->deleteJson("/api/v1/patients/{$patient->id}", [], deletionHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('outcome', 'archived');

    expect(patientRow($patient->id)?->deleted_at)->not->toBeNull();
})->group('security');

it('refuses to delete a patient from another clinic', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();

    $victim = Patient::factory()->create(['workspace_id' => $otherWs->id]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/patients/{$victim->id}", [], deletionHeaders($ws->id))
        ->assertNotFound();

    // Still there, and not even soft-deleted.
    expect(patientRow($victim->id))->not->toBeNull()
        ->and(patientRow($victim->id)?->deleted_at)->toBeNull();
})->group('security');

it('forbids deletion to a clinic member without patients.manage', function () {
    $scenario = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    Sanctum::actingAs($scenario['recUser']);

    // Reception currently has patients.manage. Restrict the real membership to
    // billing so this proves the route gate, rather than merely hiding a button.
    setMemberRoles($scenario, 'recUser', ['billing']);

    $this->deleteJson(
        "/api/v1/patients/{$patient->id}",
        [],
        deletionHeaders($scenario['clinic']->id),
    )->assertForbidden();

    expect(patientRow($patient->id)?->deleted_at)->toBeNull();
})->group('security');

it('an archived patient stops showing up in the list', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $kept = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Se queda']);
    $removed = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Se va']);

    $this->deleteJson("/api/v1/patients/{$removed->id}", [], deletionHeaders($ws->id))->assertOk();

    $names = collect($this->getJson('/api/v1/patients', deletionHeaders($ws->id))->json('data'))
        ->pluck('name');

    expect($names)->toContain('Se queda')->not->toContain('Se va');
});

it('says which patients would be deleted and which archived, before deleting any', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $bare = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Sin historia']);
    $withHistory = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Con historia']);
    MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $withHistory->id,
        'created_by'   => $doctor->id,
    ]);

    $body = $this->postJson(
        '/api/v1/patients/deletion-preview',
        ['ids' => [$bare->id, $withHistory->id]],
        deletionHeaders($ws->id),
    )->assertOk()->json();

    expect(collect($body['deletable'])->pluck('name'))->toEqual(collect(['Sin historia']))
        ->and(collect($body['archivable'])->pluck('name'))->toEqual(collect(['Con historia']));

    // A preview must not have side effects — both are still exactly as they were.
    expect(Patient::find($bare->id))->not->toBeNull()
        ->and(Patient::find($withHistory->id))->not->toBeNull();
});

it('refuses a deletion preview that includes a patient from another clinic', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();

    $mine = Patient::factory()->create(['workspace_id' => $ws->id]);
    $theirs = Patient::factory()->create(['workspace_id' => $otherWs->id, 'name' => 'Ajena']);

    Sanctum::actingAs($user);

    $this->postJson(
        '/api/v1/patients/deletion-preview',
        ['ids' => [$mine->id, $theirs->id]],
        deletionHeaders($ws->id),
    )->assertNotFound();
})->group('security');

it('purges every trace-free patient in the active workspace and attributes every deletion to its owner', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $first = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $second = Patient::factory()->create(['workspace_id' => $workspace->id]);
    [, , $otherWorkspace] = makeDoctorWithWorkspace();
    $otherWorkspacePatient = Patient::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $preview = $this->postJson('/api/v1/patients/purge-preview', [], deletionHeaders($workspace->id))
        ->assertOk()
        ->json('data');

    expect($preview['total'])->toBe(2)
        ->and($preview['deletable'])->toBe(2)
        ->and($preview['blocked'])->toBe(0);

    $this->postJson('/api/v1/patients/purge', [
        'confirmation' => 'ELIMINAR TODOS',
        'challenge' => $preview['challenge'],
    ], deletionHeaders($workspace->id))
        ->assertOk()
        ->assertJsonPath('data.deleted', 2);

    expect(patientRow($first->id))->toBeNull()
        ->and(patientRow($second->id))->toBeNull()
        ->and(patientRow($otherWorkspacePatient->id))->not->toBeNull();

    $this->assertDatabaseHas('audit_log', [
        'table_name' => 'patients',
        'record_id' => $first->id,
        'operation' => 'DELETE',
        'user_id' => $user->id,
    ]);
})->group('security');

it('does not purge any patient when even one current patient has a clinical trace', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $traceFree = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $retained = Patient::factory()->create(['workspace_id' => $workspace->id]);
    MedicalRecord::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $retained->id,
        'created_by' => $doctor->id,
    ]);

    $preview = $this->postJson('/api/v1/patients/purge-preview', [], deletionHeaders($workspace->id))
        ->assertOk()
        ->json('data');

    expect($preview['deletable'])->toBe(1)
        ->and($preview['blocked'])->toBe(1);

    $this->postJson('/api/v1/patients/purge', [
        'confirmation' => 'ELIMINAR TODOS',
        'challenge' => $preview['challenge'],
    ], deletionHeaders($workspace->id))
        ->assertConflict()
        ->assertJsonPath('data.blocked', 1);

    expect(patientRow($traceFree->id))->not->toBeNull()
        ->and(patientRow($retained->id))->not->toBeNull();
})->group('security');

it('forbids a non-owner from previewing a workspace purge even when they can manage patients', function () {
    $scenario = makeClinicScenario();
    Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    Sanctum::actingAs($scenario['recUser']);

    $this->postJson('/api/v1/patients/purge-preview', [], deletionHeaders($scenario['clinic']->id))
        ->assertForbidden();
})->group('security');

it('rejects an obsolete purge challenge without deleting any patient', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $first = Patient::factory()->create(['workspace_id' => $workspace->id]);

    $preview = $this->postJson('/api/v1/patients/purge-preview', [], deletionHeaders($workspace->id))
        ->assertOk()
        ->json('data');

    $second = Patient::factory()->create(['workspace_id' => $workspace->id]);

    $this->postJson('/api/v1/patients/purge', [
        'confirmation' => 'ELIMINAR TODOS',
        'challenge' => $preview['challenge'],
    ], deletionHeaders($workspace->id))
        ->assertConflict();

    expect(patientRow($first->id))->not->toBeNull()
        ->and(patientRow($second->id))->not->toBeNull();
})->group('security');

it('requires the explicit phrase before purging a workspace', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $preview = $this->postJson('/api/v1/patients/purge-preview', [], deletionHeaders($workspace->id))
        ->assertOk()
        ->json('data');

    $this->postJson('/api/v1/patients/purge', [
        'challenge' => $preview['challenge'],
    ], deletionHeaders($workspace->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('confirmation');

    expect(patientRow($patient->id))->not->toBeNull();
})->group('security');
