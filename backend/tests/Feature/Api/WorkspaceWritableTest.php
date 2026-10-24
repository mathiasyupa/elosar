<?php

use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Models\WorkspaceEntitlement;
use Laravel\Sanctum\Sanctum;

it('locks a permitted tenant mutation when the workspace is read only', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients', minimalPatientData(), [
        'X-Workspace-Id' => (string) $workspace->id,
    ])
        ->assertStatus(423)
        ->assertJsonPath('code', 'workspace_read_only')
        ->assertJsonPath('message', 'Este workspace está en modo de solo lectura.');

    $this->assertDatabaseCount('patients', 0);
});

it('keeps tenant GET, HEAD, and document downloads available when the workspace is read only', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    Sanctum::actingAs($user);

    $certificateId = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type' => MedicalCertificate::TYPE_ATTENDANCE,
        'title' => 'Constancia de atención médica',
        'body' => 'La paciente asistió a una consulta médica.',
    ], ['X-Workspace-Id' => (string) $workspace->id])
        ->assertCreated()
        ->json('data.id');
    $workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $workspace->id])
        ->assertOk();
    $this->call('HEAD', '/api/v1/appointments', [], [], [], [
        'HTTP_X_WORKSPACE_ID' => (string) $workspace->id,
        'HTTP_ACCEPT' => 'application/json',
    ])->assertOk();
    $this->get('/api/v1/certificates/'.$certificateId.'/pdf', [
        'X-Workspace-Id' => (string) $workspace->id,
    ])->assertOk();
});

it('returns a permission 403 before evaluating the writable entitlement', function () {
    $scenario = makeClinicScenario();
    $scenario['clinic']->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);
    Sanctum::actingAs($scenario['nurseUser']);

    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'no-permiso@clinica.test',
        'role' => 'nurse',
    ], ['X-Workspace-Id' => (string) $scenario['clinic']->id])
        ->assertForbidden();

    $this->assertDatabaseMissing('clinic_invites', [
        'email' => 'no-permiso@clinica.test',
    ]);
});

it('still lets a doctor close a consultation that was already open', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $headers = ['X-Workspace-Id' => (string) $workspace->id];

    Sanctum::actingAs($user);

    $recordId = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Cefalea tensional',
        'treatment' => 'Analgésico y reposo',
    ], $headers)->assertCreated()->json('data.id');

    // The trial lapses with the patient still in the room.
    $workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    $this->postJson("/api/v1/records/{$recordId}/close", [], $headers)
        ->assertSuccessful();

    // But a new consultation cannot be started.
    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Otra consulta',
        'diagnosis' => 'Faringitis',
        'treatment' => 'Hidratación',
    ], $headers)
        ->assertStatus(423)
        ->assertJsonPath('code', 'workspace_read_only');
});

it('still lets the clinic void an invoice and collect care already given', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $headers = ['X-Workspace-Id' => (string) $workspace->id];

    Sanctum::actingAs($user);

    $invoiceId = $this->postJson('/api/v1/invoices', [
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'issued_at' => now()->toDateString(),
        'items' => [
            ['description' => 'Consulta médica general', 'quantity' => 1, 'unit_price' => 100, 'iva_rate' => 15],
        ],
    ], $headers)->assertCreated()->json('data.id');

    $workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    // Money already earned can still be collected.
    $this->postJson("/api/v1/invoices/{$invoiceId}/payments", [
        'amount' => '115.00',
        'payment_method' => 'cash',
        'payment_date' => now()->toDateString(),
    ], $headers)->assertSuccessful();

    // Issuing a brand new invoice is not allowed.
    $this->postJson('/api/v1/invoices', [
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'issued_at' => now()->toDateString(),
        'items' => [
            ['description' => 'Otra consulta', 'quantity' => 1, 'unit_price' => 100, 'iva_rate' => 15],
        ],
    ], $headers)
        ->assertStatus(423)
        ->assertJsonPath('code', 'workspace_read_only');
});

it('returns a suspension 403 instead of a writable-entitlement lock', function () {
    $scenario = makeClinicScenario();
    $scenario['clinic']->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);
    $scenario['clinic']->update(['suspended_at' => now(), 'suspended_reason' => 'Verificación pendiente']);
    Sanctum::actingAs($scenario['ownerUser']);

    $this->postJson('/api/v1/patients', minimalPatientData(), [
        'X-Workspace-Id' => (string) $scenario['clinic']->id,
    ])
        ->assertForbidden()
        ->assertJsonMissing(['code' => 'workspace_read_only']);
});
