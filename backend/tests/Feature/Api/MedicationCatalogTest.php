<?php

use App\Models\Medication;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\seed;

it('searches global medications and workspace-specific entries', function () {
    seed(\Database\Seeders\MedicationSeeder::class);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    Medication::create([
        'workspace_id'          => $ws->id,
        'name'                  => 'Crema Clínica Especial',
        'concentration'         => '2%',
        'default_dose_quantity' => 1,
        'default_dose_unit'     => 'other',
        'default_frequency'     => 'every_12_hours',
        'default_duration_days' => 7,
        'route'                 => 'topical',
        'is_active'             => true,
    ]);

    $this->getJson('/api/v1/medications?q=Paracet', $h)
        ->assertOk()
        ->assertJsonFragment(['name' => 'Paracetamol']);

    $this->getJson('/api/v1/medications?q=Crema', $h)
        ->assertOk()
        ->assertJsonFragment(['name' => 'Crema Clínica Especial']);
});

it('lets a doctor save a workspace medication', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->postJson('/api/v1/medications', [
        'name'                  => 'Jarabe custom',
        'concentration'         => '120ml',
        'default_dose_quantity' => 5,
        'default_dose_unit'     => 'ml',
        'default_frequency'     => 'every_8_hours',
        'default_duration_days' => 5,
        'route'                 => 'oral',
    ], $h)
        ->assertStatus(201)
        ->assertJsonPath('data.is_global', false)
        ->assertJsonPath('data.name', 'Jarabe custom');

    $this->assertDatabaseHas('medications', [
        'workspace_id' => $ws->id,
        'name'         => 'Jarabe custom',
    ]);
});

it('forbids deleting global medications', function () {
    seed(\Database\Seeders\MedicationSeeder::class);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $global = Medication::whereNull('workspace_id')->first();

    $this->deleteJson("/api/v1/medications/{$global->id}", [], $h)
        ->assertStatus(404);
});

it('isolates workspace medications between tenants', function () {
    [, $userA, $wsA] = makeDoctorWithWorkspace();
    [, $userB, $wsB] = makeDoctorWithWorkspace();

    Sanctum::actingAs($userA);
    $medId = $this->postJson('/api/v1/medications', [
        'name' => 'Solo clínica A',
    ], ['X-Workspace-Id' => (string) $wsA->id])
        ->assertStatus(201)
        ->json('data.id');

    Sanctum::actingAs($userB);
    $this->deleteJson("/api/v1/medications/{$medId}", [], ['X-Workspace-Id' => (string) $wsB->id])
        ->assertStatus(404);

    $this->getJson('/api/v1/medications?q=Solo', ['X-Workspace-Id' => (string) $wsB->id])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
