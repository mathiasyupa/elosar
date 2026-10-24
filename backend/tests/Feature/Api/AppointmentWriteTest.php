<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/** Headers that select the active workspace for a tenant API request. */
function apptHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────

it('creates an appointment in the active workspace', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/appointments', [
        'patient_id'       => $patient->id,
        'appointment_date' => now()->addDay()->toIso8601String(),
        // Was 'first_visit', which `type` now rejects. Not an oversight in the
        // closed set: whether this is a first visit is already derived from the
        // patient (`is_first_visit`, shown as "1ª" in the agenda). A second,
        // hand-set copy of that fact would eventually contradict it.
        'type'             => 'consultation',
        'channel'          => 'in_person',
    ], apptHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['status'])->toBe('scheduled');

    $this->assertDatabaseHas('appointments', [
        'id'           => $data['id'],
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'doctor_id'    => $doctor->id, // defaults to the authenticated doctor
    ]);
});

it('rejects creating an appointment without a patient_id', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'appointment_date' => now()->addDay()->toIso8601String(),
    ], apptHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('patient_id');
});

// ─────────────────────────────────────────────
// Update / cancel / complete
// ─────────────────────────────────────────────

it('updates an appointment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appointment = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
    ]);

    Sanctum::actingAs($user);

    $newDate = now()->addDays(3)->startOfHour();

    $this->putJson("/api/v1/appointments/{$appointment->id}", [
        'appointment_date' => $newDate->toIso8601String(),
        'notes'            => 'Rescheduled by phone',
    ], apptHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.id', $appointment->id);

    expect($appointment->fresh()->notes)->toBe('Rescheduled by phone');
});

it('cancels an appointment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $appointment = Appointment::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appointment->id}/cancel", [], apptHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($appointment->fresh()->status)->toBe('cancelled');
});

it('completes an appointment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $appointment = Appointment::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appointment->id}/complete", [], apptHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($appointment->fresh()->status)->toBe('completed');
});

// ─────────────────────────────────────────────
// Multi-tenant isolation
// ─────────────────────────────────────────────

it('returns 404 when scheduling for a patient of another workspace', function () {
    [, $user, $ws]   = makeDoctorWithWorkspace();
    $foreignWs        = Workspace::factory()->create();
    $foreignPatient   = Patient::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'patient_id'       => $foreignPatient->id,
        'appointment_date' => now()->addDay()->toIso8601String(),
    ], apptHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseMissing('appointments', ['patient_id' => $foreignPatient->id]);
});

it('rejects assigning a doctor that is not a member of the active workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // A doctor belonging to a completely different workspace.
    $foreignDoctor = Doctor::factory()->create();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'patient_id'       => $patient->id,
        'doctor_id'        => $foreignDoctor->id,
        'appointment_date' => now()->addDay()->toIso8601String(),
    ], apptHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('doctor_id');
});

// ─────────────────────────────────────────────
// WhatsApp reminder (click-to-chat, manual)
// ─────────────────────────────────────────────

it('records a WhatsApp reminder without touching the email reminder', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appointment = Appointment::factory()->create([
        'workspace_id'     => $ws->id,
        'patient_id'       => $patient->id,
        'doctor_id'        => $doctor->id,
        'appointment_date' => now()->addDay(),
        'status'           => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appointment->id}/mark-reminded", [], apptHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.whatsapp_reminded_at', fn ($v) => $v !== null)
        ->assertJsonPath('data.reminder_sent_at', null);

    expect($appointment->fresh()->whatsapp_reminded_at)->not->toBeNull()
        ->and($appointment->fresh()->reminder_sent_at)->toBeNull();
});
