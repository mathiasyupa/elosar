<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| `?date=all` backs the searchable "Citas y atenciones" list, not a literal
| unbounded export. Without a floor it re-fetches a clinic's entire history
| on every load, growing forever. See knowledge/CURRENT_STATE.md.
|--------------------------------------------------------------------------
*/

it('bounds ?date=all to the last three years instead of the entire history', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $recent = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->subMonths(6),
    ]);

    $ancient = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->subYears(4),
    ]);

    Sanctum::actingAs($user);

    $ids = collect($this->getJson('/api/v1/appointments?date=all', ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->json('data'))
        ->pluck('id');

    expect($ids)->toContain($recent->id)
        ->not->toContain($ancient->id);
});

it('leaves an explicit ?from=&to= range with no floor of its own', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $ancient = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'appointment_date' => now()->subYears(4),
    ]);

    Sanctum::actingAs($user);

    $ids = collect($this->getJson(
        '/api/v1/appointments?from='.now()->subYears(5)->toDateString().'&to='.now()->subYears(3)->toDateString(),
        ['X-Workspace-Id' => (string) $ws->id]
    )
        ->assertOk()
        ->json('data'))
        ->pluck('id');

    expect($ids)->toContain($ancient->id);
});
