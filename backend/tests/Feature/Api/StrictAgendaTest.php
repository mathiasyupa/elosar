<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Strict per-doctor agenda
|--------------------------------------------------------------------------
| A plain doctor member of a clinic (role 'doctor', not owner, not admin)
| only sees their own appointments, and can only SCHEDULE for themselves —
| the attending doctor is always the real one, never reassignable. Front desk
| / nurse / owner / admin see the whole clinic agenda and pick any doctor.
*/

function strictAgendaHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** Two same-day appointments: one for the member doctor, one for the owner. */
function seedTwoDoctorAgenda(array $s): array
{
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $mine = Appointment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'doctor_id' => $s['memDoctor']->id,
        'appointment_date' => now()->setHour(9)->setMinute(0),
    ]);

    $others = Appointment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'doctor_id' => $s['ownerDoctor']->id,
        'appointment_date' => now()->setHour(10)->setMinute(0),
    ]);

    return [$mine, $others];
}

// The clinic asked (2026-10-15) for every doctor to see today's queue and a
// patient's past visits with colleagues. Writing stays per doctor (below).
it('shows a plain doctor member the whole clinic agenda', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorAgenda($s);

    Sanctum::actingAs($s['memUser']);
    $ids = collect(
        $this->getJson('/api/v1/appointments', strictAgendaHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->toContain($others->id);
});

it('lets a plain member filter the agenda by a colleague', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorAgenda($s);

    Sanctum::actingAs($s['memUser']);
    $ids = collect(
        $this->getJson('/api/v1/appointments?doctor_id='.$s['ownerDoctor']->id, strictAgendaHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($others->id)
        ->not->toContain($mine->id);
});

it('shows reception the whole clinic agenda', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorAgenda($s);

    Sanctum::actingAs($s['recUser']);
    $ids = collect(
        $this->getJson('/api/v1/appointments', strictAgendaHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->toContain($others->id);
});

it('shows the owner the whole clinic agenda', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorAgenda($s);

    Sanctum::actingAs($s['ownerUser']);
    $ids = collect(
        $this->getJson('/api/v1/appointments', strictAgendaHeaders($s['clinic']->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($mine->id)
        ->toContain($others->id);
});

it('keeps the solo doctor seeing their personal-workspace agenda', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appt = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->setHour(11)->setMinute(0),
    ]);

    Sanctum::actingAs($user);
    $ids = collect(
        $this->getJson('/api/v1/appointments', strictAgendaHeaders($ws->id))
            ->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($appt->id);
});

// ─────────────────────────────────────────────
// By-id access: read a colleague's appointment, never change it
// ─────────────────────────────────────────────

it('lets a plain doctor member read a colleague appointment by id', function () {
    $s = makeClinicScenario();
    [$mine, $others] = seedTwoDoctorAgenda($s);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['memUser']);

    $this->getJson("/api/v1/appointments/{$others->id}", $h)->assertOk();
    $this->getJson("/api/v1/appointments/{$mine->id}", $h)->assertOk();
});

it('404s when a plain doctor member cancels or completes a colleague appointment', function () {
    $s = makeClinicScenario();
    [, $others] = seedTwoDoctorAgenda($s);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['memUser']);

    $this->postJson("/api/v1/appointments/{$others->id}/cancel", [], $h)->assertNotFound();
    $this->postJson("/api/v1/appointments/{$others->id}/complete", [], $h)->assertNotFound();
    $this->putJson("/api/v1/appointments/{$others->id}", ['notes' => 'x'], $h)->assertNotFound();

    // Untouched — still the owner's scheduled appointment.
    expect($others->fresh()->status)->toBe('scheduled');
});

it('still lets reception manage any appointment by id', function () {
    $s = makeClinicScenario();
    [$mine] = seedTwoDoctorAgenda($s);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['recUser']);

    $this->getJson("/api/v1/appointments/{$mine->id}", $h)->assertOk();
    $this->postJson("/api/v1/appointments/{$mine->id}/cancel", [], $h)->assertOk();
});

// ─────────────────────────────────────────────
// Write: honest attribution (no reassigning to a colleague)
// ─────────────────────────────────────────────

it('forces a plain doctor member to schedule for themselves even if they send another doctor', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['memUser']); // plain doctor member

    // Carla tries to book the appointment FOR the owner doctor.
    $data = $this->postJson('/api/v1/appointments', [
        'patient_id' => $patient->id,
        'appointment_date' => now()->addDay()->toIso8601String(),
        'doctor_id' => $s['ownerDoctor']->id,
    ], $h)->assertStatus(201)->json('data');

    // The attending doctor is recorded as themselves — never the colleague.
    expect($data['doctor_id'])->toBe($s['memDoctor']->id)
        ->and($data['doctor_id'])->not->toBe($s['ownerDoctor']->id);
});

it('lets reception schedule an appointment for a chosen doctor', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['recUser']); // front desk — coordinates everyone's agenda

    $data = $this->postJson('/api/v1/appointments', [
        'patient_id' => $patient->id,
        'appointment_date' => now()->addDay()->toIso8601String(),
        'doctor_id' => $s['memDoctor']->id,
    ], $h)->assertStatus(201)->json('data');

    expect($data['doctor_id'])->toBe($s['memDoctor']->id);
});

it('keeps a plain doctor member as attending when they edit and try to reassign', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $appt = Appointment::factory()->create([
        'workspace_id' => $s['clinic']->id,
        'patient_id' => $patient->id,
        'doctor_id' => $s['memDoctor']->id,
        'status' => 'scheduled',
    ]);
    $h = strictAgendaHeaders($s['clinic']->id);

    Sanctum::actingAs($s['memUser']);

    $data = $this->putJson("/api/v1/appointments/{$appt->id}", [
        'doctor_id' => $s['ownerDoctor']->id,
    ], $h)->assertOk()->json('data');

    expect($data['doctor_id'])->toBe($s['memDoctor']->id);
});

it('never reads an appointment of another clinic by id', function () {
    $s = makeClinicScenario();
    [, $others] = seedTwoDoctorAgenda($s);
    [, $outsiderUser, $outsiderWs] = makeDoctorWithWorkspace();

    Sanctum::actingAs($outsiderUser);
    // WorkspaceScope keeps the read clinic-wide, never cross-tenant.
    $this->getJson("/api/v1/appointments/{$others->id}", strictAgendaHeaders($outsiderWs->id))->assertNotFound();
});
