<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

/*
| `?has_insurance=1` filter (Seguros screen) and the `is_first_visit` flag used by
| the agenda. Both must respect WorkspaceScope — an insured/visited patient from a
| foreign workspace must never appear here.
*/

function insuranceHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('filters patients to only those with insurance via ?has_insurance=1', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Con Seguro', 'insurance_provider' => 'Salud S.A.']);
    Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Sin Seguro', 'insurance_provider' => null]);

    Sanctum::actingAs($user);

    $names = collect(
        $this->getJson('/api/v1/patients?has_insurance=1', insuranceHeaders($ws->id))
            ->assertOk()
            ->json('data')
    )->pluck('name');

    expect($names)->toContain('Con Seguro')
        ->and($names)->not->toContain('Sin Seguro');
});

it('marks a patient with no records as is_first_visit on the list', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    $fresh   = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Nuevo Paciente']);
    $veteran = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Conocido']);
    MedicalRecord::factory()->forWorkspace($ws, $veteran, $doctor)->create();

    Sanctum::actingAs($user);

    $byName = collect(
        $this->getJson('/api/v1/patients', insuranceHeaders($ws->id))
            ->assertOk()
            ->json('data')
    )->keyBy('name');

    expect($byName['Nuevo Paciente']['is_first_visit'])->toBeTrue()
        ->and($byName['Paciente Conocido']['is_first_visit'])->toBeFalse();
});

it('exposes is_first_visit and last_visit_at on the agenda patient', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    $fresh   = Patient::factory()->create(['workspace_id' => $ws->id]);
    $veteran = Patient::factory()->create(['workspace_id' => $ws->id]);
    MedicalRecord::factory()->forWorkspace($ws, $veteran, $doctor)->create(['created_at' => now()->subDays(3)]);

    Appointment::factory()->create([
        'workspace_id'     => $ws->id,
        'doctor_id'        => $doctor->id,
        'patient_id'       => $fresh->id,
        'appointment_date' => now()->setTime(9, 0),
    ]);
    Appointment::factory()->create([
        'workspace_id'     => $ws->id,
        'doctor_id'        => $doctor->id,
        'patient_id'       => $veteran->id,
        'appointment_date' => now()->setTime(10, 0),
    ]);

    Sanctum::actingAs($user);

    $byPatient = collect(
        $this->getJson('/api/v1/appointments?date=' . now()->toDateString(), insuranceHeaders($ws->id))
            ->assertOk()
            ->json('data')
    )->keyBy('patient.id');

    expect($byPatient[$fresh->id]['patient']['is_first_visit'])->toBeTrue()
        ->and($byPatient[$fresh->id]['patient']['last_visit_at'])->toBeNull()
        ->and($byPatient[$veteran->id]['patient']['is_first_visit'])->toBeFalse()
        ->and($byPatient[$veteran->id]['patient']['last_visit_at'])->toBe(now()->subDays(3)->toDateString());
});
