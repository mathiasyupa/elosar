<?php

use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/**
 * Where a walk-in consultation lands in a SOLO consultorio.
 *
 * In a clinic, closing a consultation with no appointment behind it creates a
 * `walk_in` appointment so the visit reaches the cash desk. Personal workspaces
 * were deliberately excluded from that — the solo doctor charges straight from
 * the closing screen, so no appointment was needed for billing.
 *
 * The side effect nobody looked for: "Citas y atenciones" lists APPOINTMENTS.
 * With no appointment row, the visit is invisible there, and a doctor who just
 * finished a consultation and goes looking for it concludes it was not saved.
 */
it('saves the consultation in a personal workspace', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Migraña',
        'diagnosis' => 'Migraña sin aura',
        'treatment' => 'Analgesia',
    ], ['X-Workspace-Id' => (string) $ws->id])->assertStatus(201)->json('data');

    // The clinical record IS persisted. Nothing was lost.
    $this->assertDatabaseHas('medical_records', [
        'id' => $data['id'],
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Migraña',
    ]);
});

it('shows that visit in "Citas y atenciones"', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $record = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Migraña',
        'diagnosis' => 'Migraña sin aura',
        'treatment' => 'Analgesia',
    ], ['X-Workspace-Id' => (string) $ws->id])->assertStatus(201)->json('data');

    // The tab a doctor checks after finishing lists APPOINTMENTS. Without one,
    // a saved consultation read as lost — which is exactly what was reported.
    $appointments = $this->getJson('/api/v1/appointments?date=all', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->json('data');

    expect($appointments)->toHaveCount(1)
        ->and($appointments[0]['status'])->toBe('completed')
        ->and($record['appointment_id'])->toBe($appointments[0]['id']);
});

it('lets a solo doctor find an unbilled visit in "Por cobrar"', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Migraña',
        'diagnosis' => 'Migraña sin aura',
        'treatment' => 'Analgesia',
    ], ['X-Workspace-Id' => (string) $ws->id])->assertStatus(201);

    // The deliberate consequence: skipping "Emitir factura" no longer loses the
    // visit. The work happened and has not been charged, so it is pending.
    $billables = $this->getJson('/api/v1/clinic/billables', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->json('data');

    expect($billables)->toHaveCount(1)
        ->and($billables[0]['patient']['name'])->toBe($patient->name);
});

it('does create the appointment in a clinic, which is why the gap is invisible in testing', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    Sanctum::actingAs($s['memUser']);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Migraña',
        'diagnosis' => 'Migraña sin aura',
        'treatment' => 'Analgesia',
    ], ['X-Workspace-Id' => (string) $s['clinic']->id])->assertStatus(201)->json('data');

    expect($data['appointment_id'])->not->toBeNull();

    $this->assertDatabaseHas('appointments', [
        'id' => $data['appointment_id'],
        'type' => 'walk_in',
        'status' => 'completed',
    ]);
});
