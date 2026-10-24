<?php

use App\Models\WorkspaceEntitlement;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

/**
 * The shape medico-web reads to decide what to show and what to disable.
 * Breaking it silently breaks the app, so it is asserted field by field.
 */
it('serializes the workspace entitlement without dropping any existing field', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = $this->getJson('/api/v1/workspaces')
        ->assertOk()
        ->json('data.0');

    expect($payload)->toHaveKeys([
        'id', 'name', 'type', 'is_clinic', 'logo_path',
        'sri_enabled', 'membership_role', 'roles', 'is_owner', 'permissions',
        'entitlement',
    ]);

    // The legacy field is gone: a second, unmaintained answer to "what is this
    // workspace's status" is worse than no answer.
    expect($payload)->not->toHaveKey('subscription_status');

    expect($payload['entitlement'])->toMatchArray([
        'state' => WorkspaceEntitlement::STATE_TRIAL,
        'effective_access' => 'full',
        'can_write' => true,
        'plan_code' => WorkspaceEntitlement::PLAN_PERSONAL,
        'bundle_eligible' => false,
        'is_legacy_review' => false,
    ]);

    expect($payload['entitlement']['clinical_seats'])->toMatchArray([
        'included' => 1,
        'active_doctors' => 0,
        'additional' => 0,
    ])
        ->and($payload['entitlement']['trial_ends_at'])->not->toBeNull()
        ->and($payload['entitlement']['access_ends_at'])->toBeNull();
});

it('reports a lapsed trial as unwritable even before the nightly command runs', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $workspace->entitlement()->update(['trial_ends_at' => now()->subMinute()]);

    Sanctum::actingAs($user);

    $entitlement = $this->getJson('/api/v1/workspaces')->assertOk()->json('data.0.entitlement');

    // The stored state is still `trial`; effective access is what the UI gates on.
    expect($entitlement['state'])->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($entitlement['effective_access'])->toBe('read_only')
        ->and($entitlement['can_write'])->toBeFalse();

    // And the API agrees with itself: the write is actually refused.
    $this->postJson('/api/v1/patients', minimalPatientData(), [
        'X-Workspace-Id' => (string) $workspace->id,
    ])->assertStatus(423);
});

it('keeps a clinic usable when the owner personal workspace has lapsed', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($doctor, 'Clínica Independiente');

    $personal->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    Sanctum::actingAs($user);

    $workspaces = collect($this->getJson('/api/v1/workspaces')->assertOk()->json('data'))
        ->keyBy('id');

    expect($workspaces[$personal->id]['entitlement']['can_write'])->toBeFalse()
        ->and($workspaces[$clinic->id]['entitlement']['can_write'])->toBeTrue()
        // Both stay listed: a read-only workspace is still readable.
        ->and($workspaces)->toHaveCount(2);

    // Writing in the clinic still works.
    $this->postJson('/api/v1/patients', minimalPatientData(), [
        'X-Workspace-Id' => (string) $clinic->id,
    ])->assertCreated();
});

it('flags the bundle when the same owner runs a consulting room and a clinic', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    app(ClinicService::class)->createClinic($doctor, 'Clínica del Paquete');

    Sanctum::actingAs($user);

    $workspaces = collect($this->getJson('/api/v1/workspaces')->assertOk()->json('data'));

    expect($workspaces->every(fn (array $w): bool => $w['entitlement']['bundle_eligible'] === true))
        ->toBeTrue();
});

it('reports the extra clinical seats a clinic is billed for', function () {
    [$owner] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Cupos API');
    [$second] = makeDoctorWithWorkspace();

    $clinic->entitlement()->update(['clinical_seat_limit' => 2]);
    app(ClinicService::class)->attachDoctor($clinic->fresh(), $second, 'doctor');

    Sanctum::actingAs($owner->user);

    $seats = collect($this->getJson('/api/v1/workspaces')->assertOk()->json('data'))
        ->firstWhere('id', $clinic->id)['entitlement']['clinical_seats'];

    expect($seats)->toMatchArray([
        'included' => 2,
        'active_doctors' => 2,
        'additional' => 0,
    ]);
});
