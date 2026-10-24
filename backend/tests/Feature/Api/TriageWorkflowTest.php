<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| Nurse → doctor triage handoff: draft creation, vitals-only PATCH (nurse) and
| clinical completion (doctor). Actors come from makeClinicScenario().
*/

function triageHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function triageAppointment(int $workspaceId, int $doctorId): Appointment
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    return Appointment::create([
        'workspace_id'     => $workspaceId,
        'patient_id'       => $patient->id,
        'doctor_id'        => $doctorId,
        'appointment_date' => now(),
        'status'           => 'checked_in',
    ]);
}

it('lets a nurse open a draft and record vitals, advancing the pipeline', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)
        ->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->json('data.id');

    $this->patchJson("/api/v1/records/{$draftId}/vitals", [
        'weight_kg' => 70, 'height_cm' => 175, 'heart_rate' => 72,
    ], $h)
        ->assertOk()
        ->assertJsonPath('data.status', 'vitals_done');

    expect($appt->fresh()->status)->toBe('vitals_done');
    expect(MedicalRecord::find($draftId)->vitals()->first()->bmi)->not->toBeNull();
});

it('returns the same draft when triage is reopened (idempotent)', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $first = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');
    $second = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');

    expect($second)->toBe($first);
});

it('forbids a nurse from completing a clinical record', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');

    // records.manage denied → 403 (no diagnosis can be slipped in via PUT).
    $this->putJson("/api/v1/records/{$draftId}", [
        'diagnosis' => 'Gripe',
        'diagnoses' => [['cie10_code' => 'J11', 'type' => 'primary']],
    ], $h)->assertStatus(403);
});

it('lets a doctor complete the draft the nurse prepared', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);

    // Nurse triages.
    Sanctum::actingAs($s['nurseUser']);
    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');
    $this->patchJson("/api/v1/records/{$draftId}/vitals", ['weight_kg' => 80, 'height_cm' => 180], $h)->assertOk();

    // Doctor completes.
    Sanctum::actingAs($s['memUser']);
    $this->putJson("/api/v1/records/{$draftId}", [
        'reason_for_consultation' => 'Tos y fiebre',
        'diagnosis'               => 'Infección respiratoria',
        'treatment'               => 'Reposo',
        'diagnoses'               => [['cie10_code' => 'J06', 'cie10_description' => 'IRA', 'type' => 'primary']],
        'prescriptions'           => [['medication_name' => 'Paracetamol', 'dosage' => '500mg', 'frequency' => 'cada 8h', 'quantity' => 20]],
    ], $h)
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    $record = MedicalRecord::with(['diagnoses', 'prescriptions', 'vitals', 'appointment'])->find($draftId);
    expect($record->diagnoses)->toHaveCount(1)
        ->and($record->prescriptions)->toHaveCount(1)
        ->and($record->vitals)->toHaveCount(1)             // nurse vitals preserved
        ->and($record->appointment->status)->toBe('completed');
});

it('persists vitals edited by the doctor when completing a draft', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);

    Sanctum::actingAs($s['nurseUser']);
    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');
    $this->patchJson("/api/v1/records/{$draftId}/vitals", ['weight_kg' => 70, 'height_cm' => 170], $h)->assertOk();

    Sanctum::actingAs($s['memUser']);
    $this->putJson("/api/v1/records/{$draftId}", [
        'reason_for_consultation' => 'Control',
        'diagnosis'               => 'Control sano',
        'treatment'               => 'Continuar',
        'vitals'                  => ['weight_kg' => 75, 'height_cm' => 172, 'heart_rate' => 68],
    ], $h)->assertOk();

    $vital = MedicalRecord::find($draftId)->vitals()->latest('id')->first();
    expect((float) $vital->weight_kg)->toBe(75.0)
        ->and((float) $vital->height_cm)->toBe(172.0)
        ->and($vital->heart_rate)->toBe(68);
});

it('logs an access_log entry when a doctor views a clinical record (LODP)', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);

    Sanctum::actingAs($s['nurseUser']);
    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');

    Sanctum::actingAs($s['memUser']);
    $this->getJson("/api/v1/records/{$draftId}", $h)->assertOk();

    $this->assertDatabaseHas('access_logs', [
        'user_id'      => $s['memUser']->id,
        'workspace_id' => $s['clinic']->id,
        'subject_type' => 'medical_record',
        'subject_id'   => $draftId,
        'action'       => 'view',
    ]);
});

it('forbids a receptionist from recording vitals', function () {
    $s = makeClinicScenario();
    $appt = triageAppointment($s['clinic']->id, $s['memDoctor']->id);
    $h = triageHeaders($s['clinic']->id);

    // A draft exists (created by a nurse).
    Sanctum::actingAs($s['nurseUser']);
    $draftId = $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->json('data.id');

    // Receptionist lacks vitals.create.
    Sanctum::actingAs($s['recUser']);
    $this->patchJson("/api/v1/records/{$draftId}/vitals", ['weight_kg' => 60], $h)->assertStatus(403);
    $this->postJson('/api/v1/records/draft', ['appointment_id' => $appt->id], $h)->assertStatus(403);
});
