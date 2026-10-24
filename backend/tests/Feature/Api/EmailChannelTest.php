<?php

use App\Jobs\SendAppointmentReminders;
use App\Models\Appointment;
use App\Models\Patient;
use App\Notifications\AppointmentConfirmationNotification;
use App\Notifications\AppointmentReminderNotification;
use App\Notifications\PatientGreetingNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| Server-sent email channel: appointment confirmation / reminder and patient
| greetings. Email is sent by the system (Reply-To the doctor); these assert the
| right notification reaches the patient and that a missing email is rejected.
*/

function emailHeaders(int $wsId): array
{
    return ['X-Workspace-Id' => (string) $wsId];
}

it('emails the appointment confirmation to the patient', function () {
    Notification::fake();
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appt = Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDay(), 'status' => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appt->id}/send-confirmation-email", [], emailHeaders($ws->id))
        ->assertOk();

    Notification::assertSentTo($patient, AppointmentConfirmationNotification::class);
});

it('emails the appointment reminder now and marks it sent', function () {
    Notification::fake();
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appt = Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDay(), 'status' => 'scheduled', 'reminder_sent_at' => null,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appt->id}/send-reminder-email", [], emailHeaders($ws->id))
        ->assertOk();

    Notification::assertSentTo($patient, AppointmentReminderNotification::class);
    expect($appt->fresh()->reminder_sent_at)->not->toBeNull();
});

it('rejects sending an email when the patient has no address', function () {
    Notification::fake();
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'email' => '']);
    $appt = Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDay(), 'status' => 'scheduled',
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/appointments/{$appt->id}/send-confirmation-email", [], emailHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    Notification::assertNothingSent();
});

it('emails a birthday greeting to the patient', function () {
    Notification::fake();
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$patient->id}/greeting-email", ['type' => 'birthday'], emailHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('sent', true);

    Notification::assertSentTo(
        $patient,
        PatientGreetingNotification::class,
        fn (PatientGreetingNotification $n) => $n->type === 'birthday',
    );
});

it('rejects an unknown greeting type', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/patients/{$patient->id}/greeting-email", ['type' => 'nope'], emailHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('the reminder job notifies a patient when the appointment enters the next 24 hours', function () {
    Notification::fake();
    $this->travelTo('2026-08-14 10:15:00');
    [$doctor, , $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addHours(3), 'status' => 'scheduled', 'reminder_sent_at' => null,
    ]);

    $laterPatient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $laterPatient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addHours(25), 'status' => 'scheduled', 'reminder_sent_at' => null,
    ]);

    (new SendAppointmentReminders)->handle();

    Notification::assertSentTo($patient, AppointmentReminderNotification::class);
    Notification::assertNotSentTo($laterPatient, AppointmentReminderNotification::class);
});

it('allows a rescheduled appointment to receive its next reminder', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appointment = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->addDay(),
        'status' => 'scheduled',
        'reminder_sent_at' => now(),
    ]);

    Sanctum::actingAs($user);

    $this->putJson(
        "/api/v1/appointments/{$appointment->id}",
        ['appointment_date' => now()->addDays(4)->toIso8601String()],
        emailHeaders($ws->id),
    )->assertOk();

    expect($appointment->fresh()->reminder_sent_at)->toBeNull();
});

it('delivers the daily reminders for real, not just on paper', function () {
    // No Notification::fake(): the real path, queue included. The queued
    // notification used to be rebuilt without a workspace and fail every time.
    config(['queue.default' => 'sync', 'mail.default' => 'array']);
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'email' => 'paciente@example.com']);
    $appt = Appointment::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'appointment_date' => now()->addHours(20), 'status' => 'scheduled', 'reminder_sent_at' => null,
    ]);

    (new SendAppointmentReminders)->handle();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(1)
        ->and(app(App\Support\ActiveWorkspace::class)->runUnguarded(fn () => $appt->fresh()->reminder_sent_at))->not->toBeNull();
});
