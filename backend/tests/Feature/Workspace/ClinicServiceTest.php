<?php

use App\Models\AccountTrialGrant;
use App\Models\Doctor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceMember;
use App\Services\ClinicService;
use Illuminate\Validation\ValidationException;

function doctorWithClinicTrialGrant(): Doctor
{
    $user = User::factory()->create(['account_type' => 'doctor']);

    AccountTrialGrant::query()->create([
        'user_id' => $user->id,
        'mode' => AccountTrialGrant::MODE_DOCTOR_PERSONAL_FIRST_CLINIC,
        'started_at' => now('America/Guayaquil'),
        'ends_at' => now('America/Guayaquil')->addDays(30),
    ]);

    return Doctor::factory()->create(['user_id' => $user->id]);
}

// ─────────────────────────────────────────────
// Create clinic
// ─────────────────────────────────────────────

it('creates a clinic and links the owner doctor as an active member', function () {
    $doctor = doctorWithClinicTrialGrant();

    $clinic = app(ClinicService::class)->createClinic($doctor, 'Clínica Test');

    expect($clinic)->toBeInstanceOf(Workspace::class)
        ->and($clinic->type)->toBe('clinic')
        ->and($clinic->owner_doctor_id)->toBe($doctor->id)
        ->and($clinic->invite_code)->toHaveLength(8);

    expect(WorkspaceMember::where('doctor_id', $doctor->id)
        ->where('workspace_id', $clinic->id)
        ->where('status', 'active')
        ->exists()
    )->toBeTrue();
});

it('newly created clinic starts on its included trial', function () {
    $doctor = doctorWithClinicTrialGrant();
    $clinic = app(ClinicService::class)->createClinic($doctor, 'Mi Clínica');

    // The entitlement is the only thing that says this, now that the legacy
    // `subscription_status` column is gone.
    expect($clinic->entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($clinic->entitlement->canWrite())->toBeTrue();
});

// ─────────────────────────────────────────────
it('rejects console clinic creation without a trial grant before writing access records', function () {
    $owner = Doctor::factory()->create();
    $workspacesBefore = Workspace::count();
    $membersBefore = WorkspaceMember::count();

    expect(fn () => app(ClinicService::class)->createClinic($owner, 'Clinic without entitlement'))
        ->toThrow(DomainException::class);

    expect(Workspace::count())->toBe($workspacesBefore)
        ->and(WorkspaceMember::count())->toBe($membersBefore)
        ->and(Workspace::query()
            ->where('owner_doctor_id', $owner->id)
            ->where('type', 'clinic')
            ->exists())->toBeFalse();
});

// Join by invite_code
// ─────────────────────────────────────────────

it('doctor can join a clinic with a valid invite_code', function () {
    $owner = doctorWithClinicTrialGrant();
    $joiner = Doctor::factory()->create();

    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica con Invitación');

    app(ClinicService::class)->joinByInviteCode($joiner, $clinic->invite_code);

    expect(WorkspaceMember::where('doctor_id', $joiner->id)
        ->where('workspace_id', $clinic->id)
        ->where('status', 'active')
        ->exists()
    )->toBeTrue();
});

it('throws an exception with an invalid invite_code', function () {
    $doctor = Doctor::factory()->create();

    expect(fn () => app(ClinicService::class)->joinByInviteCode($doctor, 'INVALIDO'))
        ->toThrow(ValidationException::class);
});

it('throws an exception when the invite_code belongs to a personal workspace', function () {
    $doctor = Doctor::factory()->create();
    $personal = Workspace::factory()->create(['type' => 'personal', 'invite_code' => 'PERSONAL1']);

    expect(fn () => app(ClinicService::class)->joinByInviteCode($doctor, 'PERSONAL1'))
        ->toThrow(ValidationException::class);
});

it('joining twice is idempotent and does not duplicate the membership', function () {
    $owner = doctorWithClinicTrialGrant();
    $joiner = Doctor::factory()->create();

    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica');

    app(ClinicService::class)->joinByInviteCode($joiner, $clinic->invite_code);
    app(ClinicService::class)->joinByInviteCode($joiner, $clinic->invite_code);

    expect(WorkspaceMember::where('doctor_id', $joiner->id)
        ->where('workspace_id', $clinic->id)
        ->count()
    )->toBe(1);
});

// ─────────────────────────────────────────────
// Regenerate invite_code
// ─────────────────────────────────────────────

it('regenerating the invite_code produces a different code', function () {
    $owner = doctorWithClinicTrialGrant();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica');

    $original = $clinic->invite_code;
    $new = app(ClinicService::class)->regenerateInviteCode($clinic);

    expect($new)->not->toBe($original)
        ->and($new)->toHaveLength(8);

    expect($clinic->fresh()->invite_code)->toBe($new);
});

it('cannot regenerate the invite_code on a personal workspace', function () {
    $personal = Workspace::factory()->create(['type' => 'personal']);

    expect(fn () => app(ClinicService::class)->regenerateInviteCode($personal))
        ->toThrow(ValidationException::class);
});
