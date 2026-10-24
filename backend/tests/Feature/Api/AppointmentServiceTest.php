<?php

use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/*
| Appointments carry the service provided (e.g. "Consulta Gineco-Obstétrica")
| from the workspace catalog, and a doctor/clinic can create services from the
| app. Both are workspace-isolated.
*/

function svcHeaders(int $wsId): array
{
    return ['X-Workspace-Id' => (string) $wsId];
}

it('schedules an appointment with a service from the catalog', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $service = MedicalService::factory()->create(['workspace_id' => $ws->id, 'name' => 'Consulta Gineco-Obstétrica']);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'patient_id'         => $patient->id,
        'appointment_date'   => now()->addDay()->toIso8601String(),
        'medical_service_id' => $service->id,
    ], svcHeaders($ws->id))
        ->assertStatus(201)
        ->assertJsonPath('data.medical_service_id', $service->id)
        ->assertJsonPath('data.service_name', 'Consulta Gineco-Obstétrica');
});

it('rejects a service from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $foreign = MedicalService::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/appointments', [
        'patient_id'         => $patient->id,
        'appointment_date'   => now()->addDay()->toIso8601String(),
        'medical_service_id' => $foreign->id,
    ], svcHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('medical_service_id');
});

it('lets a doctor create a service with price defaulting to zero', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/medical-services', ['name' => 'Control prenatal'], svcHeaders($ws->id))
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Control prenatal');

    $this->assertDatabaseHas('medical_services', [
        'workspace_id' => $ws->id,
        'name'         => 'Control prenatal',
        'base_price'   => '0.00',
        'category'     => 'general',
    ]);
});
