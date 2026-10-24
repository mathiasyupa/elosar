<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| Clinic operations: appointment check-in, the day queue, the team roster and
| the role-aware dashboard. Shared actors come from makeClinicScenario()
| (tests/Pest.php). Headers select the active clinic workspace.
*/

function queueHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** A scheduled appointment in the clinic for today. */
function clinicAppointment(int $workspaceId, int $doctorId): Appointment
{
    $patient = Patient::factory()->create(['workspace_id' => $workspaceId]);

    return Appointment::create([
        'workspace_id'     => $workspaceId,
        'patient_id'       => $patient->id,
        'doctor_id'        => $doctorId,
        'appointment_date' => now(),
        'status'           => 'scheduled',
    ]);
}

// ─────────────────────────────────────────────
// Check-in
// ─────────────────────────────────────────────

it('lets a receptionist check a patient in', function () {
    $s = makeClinicScenario();
    $appt = clinicAppointment($s['clinic']->id, $s['memDoctor']->id);
    Sanctum::actingAs($s['recUser']);

    $this->postJson("/api/v1/appointments/{$appt->id}/check-in", [], queueHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data.status', 'checked_in');

    expect($appt->fresh()->checked_in_at)->not->toBeNull();
});

it('forbids billing staff from checking a patient in', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja Demo', 'bill_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    $appt = clinicAppointment($s['clinic']->id, $s['memDoctor']->id);
    Sanctum::actingAs($billing);

    $this->postJson("/api/v1/appointments/{$appt->id}/check-in", [], queueHeaders($s['clinic']->id))
        ->assertStatus(403);
});

// ─────────────────────────────────────────────
// Queue
// ─────────────────────────────────────────────

it('returns the day queue with pipeline stats for a nurse', function () {
    $s = makeClinicScenario();
    clinicAppointment($s['clinic']->id, $s['memDoctor']->id);
    Sanctum::actingAs($s['nurseUser']);

    $data = $this->getJson('/api/v1/clinic/queue', queueHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    expect($data['stats'])->toHaveKeys(['waiting', 'pending_vitals', 'ready', 'completed', 'total'])
        ->and($data['appointments'])->toHaveCount(1);
});

it('forbids billing staff from reading the clinic queue', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja Demo', 'bill2_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $this->getJson('/api/v1/clinic/queue', queueHeaders($s['clinic']->id))->assertStatus(403);
});

// ─────────────────────────────────────────────
// Team
// ─────────────────────────────────────────────

it('lists the clinic team for a doctor member', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['memUser']);

    $team = $this->getJson('/api/v1/clinic/team', queueHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    // owner doctor + member doctor + nurse + receptionist
    expect(collect($team)->pluck('role')->sort()->values()->all())
        ->toEqual(['doctor', 'doctor', 'nurse', 'receptionist']);

    $owner = collect($team)->firstWhere('is_owner', true);
    expect($owner['role'])->toBe('doctor');
});

it('forbids billing staff from listing the team', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja Demo', 'bill3_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $this->getJson('/api/v1/clinic/team', queueHeaders($s['clinic']->id))->assertStatus(403);
});

// ─────────────────────────────────────────────
// Role-aware dashboard
// ─────────────────────────────────────────────

it('returns the clinic operations dashboard with a queue for a nurse', function () {
    $s = makeClinicScenario();
    clinicAppointment($s['clinic']->id, $s['memDoctor']->id);
    Sanctum::actingAs($s['nurseUser']);

    $data = $this->getJson('/api/v1/dashboard/stats', queueHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    expect($data['mode'])->toBe('clinic')
        ->and($data)->toHaveKey('queue')
        ->and($data)->not->toHaveKey('billing'); // nurse has no billing.view
});

it('gives billing staff the clinic dashboard with billing but no clinical queue', function () {
    $s = makeClinicScenario();
    $billing = makeStaff('Caja Demo', 'bill4_'.uniqid().'@clinica.test');
    app(\App\Services\ClinicService::class)->attachMember($s['clinic'], $billing, 'billing');
    Sanctum::actingAs($billing);

    $data = $this->getJson('/api/v1/dashboard/stats', queueHeaders($s['clinic']->id))
        ->assertOk()
        ->json('data');

    expect($data['mode'])->toBe('clinic')
        ->and($data)->toHaveKey('billing')
        ->and($data)->not->toHaveKey('queue'); // billing cannot see the clinical queue
});

it('keeps the personal dashboard shape for a solo doctor', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/dashboard/stats', queueHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['mode'])->toBe('personal')
        ->and($data)->toHaveKeys(['appointments_today', 'todays_appointments', 'recent_invoices']);
});

it('shows every doctor of the clinic the vitals of who is waiting, whoever they were booked with', function () {
    $s = makeClinicScenario();
    $appt = clinicAppointment($s['clinic']->id, $s['ownerDoctor']->id);
    $appt->update(['status' => 'vitals_done']);
    $record = App\Models\MedicalRecord::create([
        'workspace_id' => $s['clinic']->id, 'patient_id' => $appt->patient_id,
        'appointment_id' => $appt->id, 'status' => 'vitals_done',
    ]);
    $record->vitals()->create(['temperature' => 38.4, 'oxygen_saturation' => 97, 'recorded_at' => now()]);
    Sanctum::actingAs($s['memUser']);

    $row = collect($this->getJson('/api/v1/clinic/queue', queueHeaders($s['clinic']->id))->assertOk()->json('data.appointments'))
        ->firstWhere('id', $appt->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['vitals']['temperature'])->toBe(38.4)
        ->and($row['vitals']['oxygen_saturation'])->toBe(97);
});
