<?php

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function voidHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A visit saved the way the encounter saves it: POST finalize, the visit opening its own appointment. */
function savedWalkInVisit(Workspace $ws, Patient $patient): MedicalRecord
{
    $id = test()->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Cefalea',
        'finalize' => true,
        'closed_with_override' => true,
    ], voidHeaders($ws->id))->assertCreated()->json('data.id');

    return MedicalRecord::withoutGlobalScopes()->findOrFail($id);
}

it('lets the author void a visit, keeping it on file with who and why', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = savedWalkInVisit($ws, $patient);

    $this->postJson("/api/v1/records/{$record->id}/void", [
        'reason' => 'Se abrió en el paciente equivocado',
    ], voidHeaders($ws->id))->assertNoContent();

    $voided = MedicalRecord::withoutGlobalScopes()->withTrashed()->findOrFail($record->id);
    expect($voided->trashed())->toBeTrue()
        ->and($voided->void_reason)->toBe('Se abrió en el paciente equivocado')
        ->and($voided->voided_by)->toBe($user->id)
        ->and($voided->voided_at)->not->toBeNull();

    // Gone from the patient's history.
    $this->getJson("/api/v1/patients/{$patient->id}/records", voidHeaders($ws->id))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('cancels the appointment the visit opened for itself', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = savedWalkInVisit($ws, $patient);
    expect($record->opened_own_appointment)->toBeTrue();

    $this->postJson("/api/v1/records/{$record->id}/void", ['reason' => 'Error'], voidHeaders($ws->id))
        ->assertNoContent();

    expect(Appointment::withoutGlobalScopes()->findOrFail($record->appointment_id)->status)->toBe('cancelled');
});

it('puts an agenda appointment back to pending', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $appointment = Appointment::withoutGlobalScopes()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now(),
        'status' => 'scheduled',
        'type' => 'consultation',
        'channel' => 'in_person',
    ]);

    $id = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'reason_for_consultation' => 'Control',
        'finalize' => true,
        'closed_with_override' => true,
    ], voidHeaders($ws->id))->assertCreated()->json('data.id');
    expect($appointment->fresh()->status)->toBe('completed');

    $this->postJson("/api/v1/records/{$id}/void", ['reason' => 'Error'], voidHeaders($ws->id))
        ->assertNoContent();

    expect($appointment->fresh()->status)->toBe('scheduled');
});

it('can void a visit from any day', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = savedWalkInVisit($ws, $patient);
    MedicalRecord::withoutGlobalScopes()->whereKey($record->id)->update(['created_at' => now()->subMonths(3)]);

    $this->postJson("/api/v1/records/{$record->id}/void", ['reason' => 'Duplicada'], voidHeaders($ws->id))
        ->assertNoContent();
});

it('requires a reason', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = savedWalkInVisit($ws, $patient);

    $this->postJson("/api/v1/records/{$record->id}/void", ['reason' => ''], voidHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    expect($record->fresh()->trashed())->toBeFalse();
});

it('refuses another doctor of the clinic but lets the owner void', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    // The owner writes one visit: the colleague cannot void it.
    Sanctum::actingAs($s['ownerUser']);
    $ownersVisit = savedWalkInVisit($s['clinic'], $patient);
    Sanctum::actingAs($s['memUser']);
    $this->postJson("/api/v1/records/{$ownersVisit->id}/void", ['reason' => 'Error'], voidHeaders($s['clinic']->id))
        ->assertForbidden();
    expect($ownersVisit->fresh()->trashed())->toBeFalse();

    // The colleague writes another: the clinic owner can void it.
    $colleaguesVisit = savedWalkInVisit($s['clinic'], $patient);
    Sanctum::actingAs($s['ownerUser']);
    $this->postJson("/api/v1/records/{$colleaguesVisit->id}/void", ['reason' => 'Error'], voidHeaders($s['clinic']->id))
        ->assertNoContent();
});

it('refuses to void a visit that has already been invoiced', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = savedWalkInVisit($ws, $patient);
    Invoice::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'appointment_id' => $record->appointment_id,
        'status' => 'issued',
    ]);

    $this->postJson("/api/v1/records/{$record->id}/void", ['reason' => 'Error'], voidHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonPath('message', 'Esta atención ya fue facturada. Anula primero la factura en caja.');

    expect($record->fresh()->trashed())->toBeFalse();
});

it('returns 404 for a visit of another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, $foreignUser, $foreignWs] = makeDoctorWithWorkspace();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($foreignUser);
    $record = savedWalkInVisit($foreignWs, $foreignPatient);

    Sanctum::actingAs($user);
    $this->postJson("/api/v1/records/{$record->id}/void", ['reason' => 'Error'], voidHeaders($ws->id))
        ->assertNotFound();

    expect($record->fresh()->trashed())->toBeFalse();
});
