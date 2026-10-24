<?php

use App\Models\AccountTrialGrant;
use App\Models\Doctor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceMember;
use App\Services\ClinicService;
use Illuminate\Support\Str;

/**
 * A "legacy" workspace is one that existed before entitlements shipped: no
 * entitlement row and no account grant. Building it through the Doctor
 * lifecycle would provision both, so these fixtures write the workspace
 * directly — exactly the shape production data has on deploy day.
 */
function makeLegacyWorkspace(string $type = 'personal', string $accountType = 'doctor'): array
{
    $user = User::factory()->create(['account_type' => $accountType]);

    $workspace = Workspace::create([
        'name' => $type === 'clinic' ? 'Clínica Legacy' : 'Consultorio Legacy',
        'type' => $type,
        'invite_code' => strtoupper(Str::random(8)),
        'owner_user_id' => $user->id,
    ]);

    return [$workspace, $user];
}

it('gives every pre-existing workspace a legacy entitlement with full access', function (): void {
    [$workspace] = makeLegacyWorkspace();

    expect($workspace->entitlement)->toBeNull();

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    $entitlement = $workspace->fresh()->entitlement;

    expect($entitlement)->not->toBeNull()
        ->and($entitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($entitlement->source)->toBe(WorkspaceEntitlement::SOURCE_LEGACY_REVIEW)
        ->and($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_PERSONAL)
        ->and($entitlement->canWrite())->toBeTrue();
});

it('is safe to run twice', function (): void {
    [$workspace] = makeLegacyWorkspace();

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();
    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    expect(WorkspaceEntitlement::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

it('grandfathers the seats a clinic already uses', function (): void {
    [$clinic] = makeLegacyWorkspace('clinic');

    // Four doctors already attending in this clinic. The USD 21 plan includes
    // two — the backfill must not retroactively put them over the limit.
    foreach (range(1, 4) as $i) {
        $user = User::factory()->create(['account_type' => 'doctor']);
        $doctor = Doctor::factory()->withUser($user)->create();

        WorkspaceMember::create([
            'workspace_id' => $clinic->id,
            'user_id' => $user->id,
            'doctor_id' => $doctor->id,
            'role' => 'doctor',
            'roles' => ['doctor'],
            'status' => 'active',
        ]);
    }

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    $entitlement = $clinic->fresh()->entitlement;

    expect($entitlement->clinical_seat_limit)->toBe(4)
        ->and($entitlement->includedClinicalSeats())->toBe(4)
        ->and($entitlement->isSeatLimitReached())->toBeTrue();
});

it('issues a trial grant to an account that never had one', function (): void {
    [, $user] = makeLegacyWorkspace();

    expect($user->accountTrialGrant)->toBeNull();

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    $grant = $user->fresh()->accountTrialGrant;

    expect($grant)->not->toBeNull()
        ->and($grant->mode)->toBe(AccountTrialGrant::MODE_DOCTOR_PERSONAL_FIRST_CLINIC)
        ->and($grant->ends_at->isFuture())->toBeTrue()
        // No clinic yet: their included clinic is still theirs to claim.
        ->and($grant->first_clinic_claimed_at)->toBeNull();
});

it('does not hand a second free clinic to an account that already runs one', function (): void {
    [, $user] = makeLegacyWorkspace('clinic');

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    expect($user->fresh()->accountTrialGrant->first_clinic_claimed_at)->not->toBeNull();
});

it('lets a backfilled doctor create their first clinic', function (): void {
    [, $user] = makeLegacyWorkspace();
    $doctor = Doctor::factory()->withUser($user)->create();

    $this->artisan('elosar:backfill-workspace-entitlements')->assertSuccessful();

    $clinic = app(ClinicService::class)
        ->createClinic($doctor->fresh(), 'Clínica Nueva');

    expect($clinic->entitlement)->not->toBeNull()
        ->and($clinic->entitlement->canWrite())->toBeTrue();
});

it('never leaves a personal workspace without an entitlement', function (): void {
    // A doctor who joined through a clinic invitation: no public signup, so
    // no grant existed when their personal workspace was created.
    $user = User::factory()->create(['account_type' => 'doctor']);
    $doctor = Doctor::factory()->withUser($user)->create();

    $workspace = $doctor->fresh()->workspace;

    expect($workspace->entitlement)->not->toBeNull()
        ->and($workspace->entitlement->canWrite())->toBeTrue();
});
