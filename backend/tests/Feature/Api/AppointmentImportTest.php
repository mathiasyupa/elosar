<?php

use App\Models\Appointment;
use App\Models\MedicalService;
use App\Models\Patient;

function appointmentImportHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('imports appointments linked to existing patients', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'id_number' => '1710034065',
        'document_type' => 'cedula',
        'document_country' => 'EC',
    ]);
    MedicalService::factory()->create([
        'workspace_id' => $ws->id,
        'name' => 'Consulta Gineco-Obstétrica',
    ]);

    \Laravel\Sanctum\Sanctum::actingAs($user);

    $payload = [
        'rows' => [
            [
                'appointment_date' => '2023-09-04 10:25:00',
                'id_number' => '1710034065',
                'document_type' => 'cedula',
                'document_country' => 'EC',
                'status' => 'ATENDIDA',
                'service_name' => 'Consulta Gineco-Obstétrica',
                'channel' => 'Centro Médico San Ignacio',
                'notes' => 'cirugia',
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/appointments/import', $payload, appointmentImportHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($response['created'])->toBe(1)
        ->and($response['errors'])->toBe(0);

    $this->assertDatabaseHas('appointments', [
        'patient_id' => $patient->id,
        'status' => 'completed',
        'channel' => 'in_person',
    ]);
});

it('fails when the patient does not exist in the workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    \Laravel\Sanctum\Sanctum::actingAs($user);

    $payload = [
        'rows' => [
            [
                'appointment_date' => '2023-09-04 10:25:00',
                'id_number' => '9999999999',
                'document_type' => 'cedula',
                'document_country' => 'EC',
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/appointments/import', $payload, appointmentImportHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($response['created'])->toBe(0)
        ->and($response['errors'])->toBe(1);
});
