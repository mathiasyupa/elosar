<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/**
 * What a visit is FOR.
 *
 * `type` used to be validated as `string|max:50`, so the web form stored the
 * Spanish label ('Consulta') while the backend stored the English key
 * ('consultation'). Nothing matched the label map and the raw value leaked to
 * screen as "walk_in".
 *
 * `exams` is the point of closing the set: a patient who comes only for a
 * laboratory test is the most frequent line in the clinics we studied, and
 * billing one used to require inventing a consultation that never happened.
 */
function apptTypeHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('accepts an exams-only visit as a type of its own', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/appointments', [
        'patient_id' => $patient->id,
        'appointment_date' => now()->addDay()->format('Y-m-d H:i:s'),
        'type' => 'exams',
    ], apptTypeHeaders($ws->id))->assertStatus(201)->json('data');

    expect($data['type'])->toBe('exams');
});

it('rejects the Spanish label the form used to send', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'patient_id' => $patient->id,
        'appointment_date' => now()->addDay()->format('Y-m-d H:i:s'),
        'type' => 'Consulta',
    ], apptTypeHeaders($ws->id))->assertStatus(422)->assertJsonValidationErrors('type');
});

it('bills an exams-only visit without inventing a consultation', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // The patient came, the lab ran the test, nobody consulted anyone.
    $appointment = Appointment::create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now(),
        'status' => 'completed',
        'type' => 'exams',
    ]);

    Sanctum::actingAs($user);

    $billables = $this->getJson('/api/v1/clinic/billables', apptTypeHeaders($ws->id))
        ->assertOk()
        ->json('data');

    $mine = collect($billables)->firstWhere('appointment_id', $appointment->id);

    // It reaches the cash desk on its own, with no MedicalRecord behind it.
    expect($mine)->not->toBeNull()
        ->and($mine['services'])->toBe([]);

    $this->assertDatabaseMissing('medical_records', ['appointment_id' => $appointment->id]);
});

it('keeps every type the label map knows about', function () {
    // The web map (i18n/labels.ts) and this constant must not drift apart:
    // a key missing here is a 422 on a visit the UI happily offers.
    expect(Appointment::TYPES)->toContain('consultation', 'exams', 'procedure', 'follow_up', 'control', 'walk_in');
});
