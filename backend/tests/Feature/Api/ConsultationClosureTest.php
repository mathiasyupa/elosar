<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| Mandatory consultation closure (forcing function). The doctor can close a
| visit with the minimum (motivo), optionally overriding the missing diagnosis;
| closing completes the appointment so it reaches caja (billables). next-patient
| feeds the "atender al siguiente" button. Actors from makeClinicScenario().
*/

function closureHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A triaged (vitals_done) appointment with an open draft for the doctor. */
function closureDraft(array $scenario): array
{
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    $appt = Appointment::create([
        'workspace_id'     => $scenario['clinic']->id,
        'patient_id'       => $patient->id,
        'doctor_id'        => $scenario['memDoctor']->id,
        'appointment_date' => now(),
        'status'           => 'vitals_done',
        'checked_in_at'    => now(),
    ]);
    $draft = MedicalRecord::create([
        'workspace_id'   => $scenario['clinic']->id,
        'patient_id'     => $patient->id,
        'appointment_id' => $appt->id,
        'status'         => 'vitals_done',
    ]);

    return [$appt, $draft];
}

it('closes a consultation with only the motivo (minimum) and completes the appointment', function () {
    $s = makeClinicScenario();
    [$appt, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $this->putJson("/api/v1/records/{$draft->id}", [
        'reason_for_consultation' => 'Control de presión',
    ], $h)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($appt->fresh()->status)->toBe('completed');

    // Now reachable by caja in the billables list.
    Sanctum::actingAs($s['ownerUser']); // owner has billing.view
    $billables = $this->getJson('/api/v1/clinic/billables', $h)->assertOk()->json('data');
    expect(collect($billables)->pluck('appointment_id'))->toContain($appt->id);
});

it('closes a consultation with a prescription that carries no posology', function () {
    $s = makeClinicScenario();
    [, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    // Until 2026-09-21 this was a 422: dosage and frequency were NOT NULL, so
    // the rules had to reject a missing field rather than let the INSERT blow
    // up mid-closure. Both columns are nullable now (migration
    // make_prescription_posology_nullable) and the form no longer asks for
    // them, so the same request must close the consultation.
    $this->putJson("/api/v1/records/{$draft->id}", [
        'reason_for_consultation' => 'Control de presión',
        'prescriptions' => [
            ['medication_name' => 'Paracetamol', 'quantity' => 10],
        ],
    ], $h)->assertOk();
});

it('rejects closing a consultation with a prescription missing its medication name', function () {
    $s = makeClinicScenario();
    [, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    // medication_name is still NOT NULL and quantity is still the MSP
    // dispensing amount: both must fail validation, not the insert.
    $this->putJson("/api/v1/records/{$draft->id}", [
        'reason_for_consultation' => 'Control de presión',
        'prescriptions' => [
            ['instructions' => 'Una vez al día.'],
        ],
    ], $h)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prescriptions.0.medication_name', 'prescriptions.0.quantity']);
});

it('records the override flag when closing without a diagnosis', function () {
    $s = makeClinicScenario();
    [, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $this->putJson("/api/v1/records/{$draft->id}", [
        'reason_for_consultation' => 'Dolor leve, sin tiempo de documentar',
        'closed_with_override'    => true,
        'completion_note'         => 'Cierre rápido por alta demanda',
    ], $h)->assertOk();

    $fresh = MedicalRecord::find($draft->id);
    expect($fresh->closed_with_override)->toBeTrue()
        ->and($fresh->status)->toBe('completed')
        ->and($fresh->diagnosis)->toBeNull();
});

it('returns the next ready patient for the doctor', function () {
    $s = makeClinicScenario();
    [$appt] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $next = $this->getJson('/api/v1/clinic/next-patient?doctor_id=' . $s['memDoctor']->id, $h)
        ->assertOk()
        ->json('data');

    expect($next['appointment_id'])->toBe($appt->id)
        ->and($next['status'])->toBe('vitals_done')
        ->and($next)->toHaveKey('draft_record_id');
});

it('returns null from next-patient when the queue is clear', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['memUser']);

    $this->getJson('/api/v1/clinic/next-patient', closureHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('forbids a nurse from using next-patient (records.manage only)', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);

    $this->getJson('/api/v1/clinic/next-patient', closureHeaders($s['clinic']->id))
        ->assertStatus(403);
});

it('creates a nueva-consulta record with override and completes the linked appointment', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $appt = Appointment::create([
        'workspace_id'     => $s['clinic']->id,
        'patient_id'       => $patient->id,
        'doctor_id'        => $s['memDoctor']->id,
        'appointment_date' => now(),
        'status'           => 'with_doctor',
        'checked_in_at'    => now(),
    ]);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $this->postJson('/api/v1/records', [
        'patient_id'              => $patient->id,
        'appointment_id'          => $appt->id,
        'reason_for_consultation' => 'Control rápido',
        'closed_with_override'    => true,
    ], $h)
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed');

    expect($appt->fresh()->status)->toBe('completed');
});

it('auto-creates a billable walk-in appointment for a clinic consultation without one', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    $recordId = $this->postJson('/api/v1/records', [
        'patient_id'              => $patient->id,
        'reason_for_consultation' => 'Consulta directa sin cita',
        'closed_with_override'    => true,
    ], $h)->assertCreated()->json('data.id');

    $record = MedicalRecord::find($recordId);
    expect($record->appointment_id)->not->toBeNull();

    $appt = Appointment::find($record->appointment_id);
    expect($appt->status)->toBe('completed')
        ->and($appt->type)->toBe('walk_in');

    // Caja now sees the encounter in "Por cobrar".
    Sanctum::actingAs($s['ownerUser']);
    $billables = $this->getJson('/api/v1/clinic/billables', $h)->assertOk()->json('data');
    expect(collect($billables)->pluck('appointment_id'))->toContain($appt->id);
});

/**
 * This test asserted the opposite until 2026-08-16: a personal workspace got no
 * appointment, because a solo doctor charges from the closing screen and needed
 * none for billing.
 *
 * That reasoning was right about billing and wrong about everything else.
 * "Citas y atenciones" lists APPOINTMENTS, so a solo doctor's consultation was
 * saved and then invisible in the very tab they check afterwards — reported in
 * first real use as "I made a consultation and it was not saved". It was saved.
 *
 * Every visit now becomes an appointment. Do not narrow this back to clinics
 * without first answering where a solo doctor is supposed to see their own day.
 */
it('creates the appointment for a personal-workspace consultation too', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $recordId = $this->postJson('/api/v1/records', [
        'patient_id'              => $patient->id,
        'reason_for_consultation' => 'Consulta solo médico',
        'diagnosis'               => 'Dx',
        'treatment'               => 'Plan',
    ], closureHeaders($ws->id))->assertCreated()->json('data.id');

    $record = MedicalRecord::find($recordId);
    expect($record->appointment_id)->not->toBeNull();

    $appt = Appointment::find($record->appointment_id);
    expect($appt->status)->toBe('completed')
        ->and($appt->type)->toBe('walk_in');
});

it('hands the appointment to the doctor who actually attended it', function () {
    $s = makeClinicScenario();
    // Booked for the member doctor, who did not come in: the owner attends.
    [$appt, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);
    Sanctum::actingAs($s['ownerUser']);

    $this->putJson("/api/v1/records/{$draft->id}", ['reason_for_consultation' => 'Dolor de cabeza'], $h)->assertOk();

    expect($appt->fresh()->doctor_id)->toBe($s['ownerDoctor']->id)
        ->and($appt->fresh()->status)->toBe('completed');
});

it('stops a second doctor from overwriting a visit a colleague already signed', function () {
    $s = makeClinicScenario();
    [$appt, $draft] = closureDraft($s);
    $h = closureHeaders($s['clinic']->id);

    Sanctum::actingAs($s['memUser']);
    $this->putJson("/api/v1/records/{$draft->id}", ['reason_for_consultation' => 'Primero', 'expected_created_by' => null], $h)->assertOk();

    Sanctum::actingAs($s['ownerUser']);
    $this->putJson("/api/v1/records/{$draft->id}", ['reason_for_consultation' => 'Segundo', 'expected_created_by' => null], $h)->assertStatus(409);

    expect($draft->fresh()->reason_for_consultation)->toBe('Primero');
});

it('attends the patient\'s booked appointment of today instead of opening a walk-in', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    // Booked with the member doctor, who did not come in.
    $booked = Appointment::create([
        'workspace_id' => $s['clinic']->id, 'patient_id' => $patient->id,
        'doctor_id' => $s['memDoctor']->id, 'appointment_date' => now()->startOfHour(), 'status' => 'scheduled',
    ]);
    Sanctum::actingAs($s['ownerUser']);

    $record = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id, 'reason_for_consultation' => 'Control', 'closed_with_override' => true,
    ], closureHeaders($s['clinic']->id))->assertCreated()->json('data');

    expect($record['appointment_id'])->toBe($booked->id)
        ->and($booked->fresh()->status)->toBe('completed')
        ->and($booked->fresh()->doctor_id)->toBe($s['ownerDoctor']->id)
        ->and(Appointment::where('patient_id', $patient->id)->count())->toBe(1);
});
