<?php

use App\Models\AccountTrialGrant;
use App\Models\ClinicInvite;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

function trialLifecycleStart(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Guayaquil');
}

function registerTrialLifecycleDoctor(?string $email = null): User
{
    $specialty = Specialty::factory()->create();
    $email ??= 'trial-doctor-'.uniqid().'@example.test';

    test()->postJson('/api/v1/auth/register', [
        'name' => 'Dra. Prueba Trial',
        'email' => $email,
        'password' => 'password123',
        'id_number' => '1710034065',
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ])->assertCreated();

    return User::query()->where('email', $email)->firstOrFail();
}

it('grants a public doctor before provisioning their personal workspace entitlement', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $user = registerTrialLifecycleDoctor();
        $grant = $user->accountTrialGrant()->first();
        $doctor = $user->doctor()->firstOrFail();
        $entitlement = WorkspaceEntitlement::query()
            ->where('workspace_id', $doctor->workspace_id)
            ->first();

        expect($grant)->not->toBeNull()
            ->and($grant->mode)->toBe('doctor_personal_first_clinic')
            ->and($grant->started_at->getTimestamp())->toBe($now->getTimestamp())
            // Day 30 is a whole Ecuadorian day. A doctor who signed up at 09:00
            // keeps until 23:59:59, not until 09:00 mid-consultation.
            ->and($grant->ends_at->getTimestamp())->toBe(
                CarbonImmutable::parse('2026-09-02 23:59:59', 'America/Guayaquil')->getTimestamp(),
            )
            ->and($entitlement)->not->toBeNull()
            ->and($entitlement->plan_code)->toBe('personal')
            ->and($entitlement->state)->toBe('trial')
            ->and($entitlement->trial_ends_at->getTimestamp())->toBe($grant->ends_at->getTimestamp());
    } finally {
        $this->travelBack();
    }
});

it('does not let a pending clinic invitation suppress a public doctor trial', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $email = 'pending-invite-doctor-'.uniqid().'@example.test';
        $clinic = Workspace::factory()->clinic()->create();

        ClinicInvite::query()->create([
            'workspace_id' => $clinic->id,
            'email' => $email,
            'role' => 'doctor',
            'token' => Str::random(64),
            'expires_at' => $now->addDay(),
        ]);

        $user = registerTrialLifecycleDoctor($email);
        $doctor = $user->doctor()->firstOrFail();

        expect($user->accountTrialGrant()->exists())->toBeTrue()
            ->and(WorkspaceEntitlement::query()
                ->where('workspace_id', $doctor->workspace_id)
                ->exists())->toBeTrue();
    } finally {
        $this->travelBack();
    }
});

it('uses the original trial end date when a public doctor claims their first clinic one day later', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $user = registerTrialLifecycleDoctor();
        $originalEndTimestamp = $user->accountTrialGrant()->firstOrFail()->ends_at->getTimestamp();

        $this->travelTo($now->addDay());
        Sanctum::actingAs($user);

        $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica del segundo día'])
            ->assertCreated()
            ->json('data.id');

        $entitlement = WorkspaceEntitlement::query()
            ->where('workspace_id', $clinicId)
            ->firstOrFail();

        expect($entitlement->plan_code)->toBe('clinic')
            ->and($entitlement->state)->toBe('trial')
            ->and($entitlement->trial_ends_at->getTimestamp())->toBe($originalEndTimestamp);
    } finally {
        $this->travelBack();
    }
});

it('gives a public clinic administrator only their included clinic trial', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $email = 'trial-admin-'.uniqid().'@example.test';

        $this->postJson('/api/v1/auth/register/clinic-admin', [
            'name' => 'Administradora Trial',
            'email' => $email,
            'password' => 'password123',
            'clinic_name' => 'Clínica Admin Trial',
            'accept_terms' => true,
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();
        $grant = $user->accountTrialGrant()->first();
        $clinic = Workspace::query()
            ->where('owner_user_id', $user->id)
            ->where('type', 'clinic')
            ->firstOrFail();
        $entitlement = $clinic->entitlement()->first();

        expect($grant)->not->toBeNull()
            ->and($grant->mode)->toBe('clinic_admin_clinic_only')
            ->and(Doctor::query()->where('user_id', $user->id)->exists())->toBeFalse()
            ->and(Workspace::query()->where('owner_user_id', $user->id)->where('type', 'personal')->exists())->toBeFalse()
            ->and($entitlement)->not->toBeNull()
            ->and($entitlement->plan_code)->toBe('clinic')
            ->and($entitlement->state)->toBe('trial')
            ->and($entitlement->trial_ends_at->getTimestamp())->toBe($grant->ends_at->getTimestamp());
    } finally {
        $this->travelBack();
    }
});

it('does not let an expired clinic invitation reject public clinic-admin signup', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $email = 'expired-invite-admin-'.uniqid().'@example.test';
        $existingClinic = Workspace::factory()->clinic()->create();

        ClinicInvite::query()->create([
            'workspace_id' => $existingClinic->id,
            'email' => $email,
            'role' => 'admin',
            'token' => Str::random(64),
            'expires_at' => $now->subDay(),
        ]);

        $this->postJson('/api/v1/auth/register/clinic-admin', [
            'name' => 'Administradora con invitaciÃ³n expirada',
            'email' => $email,
            'password' => 'password123',
            'clinic_name' => 'ClÃ­nica Admin sin bloqueo',
            'accept_terms' => true,
        ])->assertCreated();

        $user = User::query()->where('email', $email)->firstOrFail();

        expect($user->accountTrialGrant()->exists())->toBeTrue()
            ->and(Workspace::query()
                ->where('owner_user_id', $user->id)
                ->where('type', 'clinic')
                ->exists())->toBeTrue();
    } finally {
        $this->travelBack();
    }
});

it('rejects a second included clinic before it writes another workspace or membership', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $user = registerTrialLifecycleDoctor();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/clinics', ['name' => 'Primera clínica incluida'])
            ->assertCreated();

        $clinicCount = Workspace::query()->where('type', 'clinic')->count();
        $membershipCount = WorkspaceMember::query()->count();

        $this->postJson('/api/v1/clinics', ['name' => 'Segunda clínica incluida'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'clinic_trial_limit_reached');

        expect(Workspace::query()->where('type', 'clinic')->count())->toBe($clinicCount)
            ->and(WorkspaceMember::query()->count())->toBe($membershipCount);
    } finally {
        $this->travelBack();
    }
});

it('rejects a clinic claim when the account trial has expired', function () {
    $now = trialLifecycleStart();
    $this->travelTo($now);

    try {
        $user = User::factory()->create(['account_type' => 'doctor']);

        // Creating the doctor provisions the account grant along with the
        // personal workspace, so age it rather than inserting a second one.
        $doctor = Doctor::factory()->create(['user_id' => $user->id]);

        $user->accountTrialGrant()->firstOrFail()->update([
            'started_at' => $now->subDays(30),
            'ends_at' => $now->subSecond(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/clinics', ['name' => 'Clínica vencida'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'clinic_trial_limit_reached');

        expect(Workspace::query()->where('owner_doctor_id', $doctor->id)->where('type', 'clinic')->exists())
            ->toBeFalse();
    } finally {
        $this->travelBack();
    }
});

it('entitles a doctor created outside public signup instead of locking them out', function () {
    // A doctor who arrives through a clinic invitation, a seeder or the admin
    // panel never touches the public signup route. Their own consulting room
    // must still be writable — a workspace without an entitlement resolves to
    // read-only, which would silently hand them a dead account.
    $user = User::factory()->create(['account_type' => 'doctor']);
    $doctor = Doctor::factory()->create(['user_id' => $user->id]);

    $grant = AccountTrialGrant::query()->where('user_id', $user->id)->first();

    expect($grant)->not->toBeNull()
        ->and($grant->first_clinic_claimed_at)->toBeNull()
        ->and($doctor->fresh()->workspace->entitlement->canWrite())->toBeTrue();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/clinics', ['name' => 'Clínica incluida'])
        ->assertCreated();

    expect(Workspace::query()->where('owner_doctor_id', $doctor->id)->where('type', 'clinic')->exists())
        ->toBeTrue();
});

it('does not grant a trial to a staff member who registers from an invitation', function () {
    $clinic = Workspace::factory()->clinic()->create();
    $email = 'invited-staff-'.uniqid().'@example.test';

    ClinicInvite::query()->create([
        'workspace_id' => $clinic->id,
        'email' => $email,
        'role' => 'receptionist',
        'token' => Str::random(64),
    ]);

    $invite = ClinicInvite::query()->where('email', $email)->firstOrFail();

    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $invite->token,
        'name' => 'Recepción Invitada',
        'password' => 'password123',
        'accept_terms' => true,
    ])->assertCreated();

    $user = User::query()->where('email', $email)->firstOrFail();

    expect(AccountTrialGrant::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
