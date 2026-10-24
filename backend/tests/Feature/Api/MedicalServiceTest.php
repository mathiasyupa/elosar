<?php

use App\Models\MedicalService;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/** Workspace header helper local to this file (avoids cross-file collisions). */
function medicalServiceHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('lets a doctor update a service in their own workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $service = MedicalService::factory()->create(['workspace_id' => $ws->id, 'name' => 'Original']);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/medical-services/{$service->id}", [
        'name'       => 'Control / seguimiento',
        'category'   => 'general',
        'base_price' => '10.00',
        'iva_rate'   => '0.00',
        'is_active'  => true,
    ], medicalServiceHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.name', 'Control / seguimiento');

    $this->assertDatabaseHas('medical_services', ['id' => $service->id, 'name' => 'Control / seguimiento']);
});

it('lets a receptionist (appointments.manage) update a service too', function () {
    $scenario = makeClinicScenario();
    $service = MedicalService::factory()->create(['workspace_id' => $scenario['clinic']->id, 'name' => 'Original']);

    Sanctum::actingAs($scenario['recUser']);

    $this->putJson("/api/v1/medical-services/{$service->id}", [
        'name' => 'Actualizado por recepción',
    ], medicalServiceHeaders($scenario['clinic']->id))->assertOk();
});

it('forbids a nurse (no appointments.manage) from updating a service', function () {
    $scenario = makeClinicScenario();
    $service = MedicalService::factory()->create(['workspace_id' => $scenario['clinic']->id]);

    Sanctum::actingAs($scenario['nurseUser']);

    $this->putJson("/api/v1/medical-services/{$service->id}", [
        'name' => 'No debería aplicar',
    ], medicalServiceHeaders($scenario['clinic']->id))->assertForbidden();
});

it('returns 404 updating a service from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreign = MedicalService::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/medical-services/{$foreign->id}", [
        'name' => 'No debería aplicar',
    ], medicalServiceHeaders($ws->id))->assertNotFound();
});

it('rejects an invalid category', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $service = MedicalService::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->putJson("/api/v1/medical-services/{$service->id}", [
        'name'     => 'Servicio',
        'category' => 'not-a-real-category',
    ], medicalServiceHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category']);
});

it('lets a doctor delete a service in their own workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $service = MedicalService::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/medical-services/{$service->id}", [], medicalServiceHeaders($ws->id))
        ->assertNoContent();

    $this->assertDatabaseMissing('medical_services', ['id' => $service->id]);
});

it('forbids a nurse (no appointments.manage) from deleting a service', function () {
    $scenario = makeClinicScenario();
    $service = MedicalService::factory()->create(['workspace_id' => $scenario['clinic']->id]);

    Sanctum::actingAs($scenario['nurseUser']);

    $this->deleteJson("/api/v1/medical-services/{$service->id}", [], medicalServiceHeaders($scenario['clinic']->id))
        ->assertForbidden();

    $this->assertDatabaseHas('medical_services', ['id' => $service->id]);
});

it('returns 404 deleting a service from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreign = MedicalService::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/medical-services/{$foreign->id}", [], medicalServiceHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseHas('medical_services', ['id' => $foreign->id]);
});
