<?php

use App\Models\AccountTrialGrant;
use App\Models\ClinicInvite;
use App\Models\Doctor;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use App\Models\WorkspaceMember;
use App\Services\TrialCampaignService;
use App\Services\WorkspaceEntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('keeps the one-time account trial grant separate from a workspace entitlement', function () {
    $user = User::factory()->create();
    $endsAt = CarbonImmutable::parse('2026-09-02 00:00:00', 'America/Guayaquil');

    $grant = AccountTrialGrant::query()->create([
        'user_id' => $user->id,
        'mode' => 'doctor_personal_first_clinic',
        'started_at' => CarbonImmutable::parse('2026-08-03 00:00:00', 'America/Guayaquil'),
        'ends_at' => $endsAt,
    ]);
    $storedGrant = $grant->fresh();

    expect($user->fresh()->accountTrialGrant->is($grant))->toBeTrue()
        ->and($storedGrant->mode)->toBe('doctor_personal_first_clinic')
        ->and($grant->first_clinic_claimed_at)->toBeNull()
        ->and($storedGrant->ends_at->getTimestamp())->toBe($endsAt->getTimestamp());
});

it('stores campaign configuration as a JSON platform setting', function () {
    PlatformSetting::query()->create([
        'key' => 'trial_campaign',
        'value' => ['starts_on' => '2026-08-03', 'ends_on' => '2026-09-02'],
    ]);

    $value = PlatformSetting::query()->where('key', 'trial_campaign')->sole()->value;

    expect($value['starts_on'])->toBe('2026-08-03')
        ->and($value['ends_on'])->toBe('2026-09-02');
});

it('keeps a personal trial entitled to one included clinical seat', function () {
    $workspace = Workspace::factory()->create(['type' => 'personal']);
    $trialEndsAt = CarbonImmutable::parse('2026-09-02 12:00:00', 'America/Guayaquil');

    $entitlement = WorkspaceEntitlement::query()->create([
        'workspace_id' => $workspace->id,
        'plan_code' => WorkspaceEntitlement::PLAN_PERSONAL,
        'state' => WorkspaceEntitlement::STATE_TRIAL,
        'source' => WorkspaceEntitlement::SOURCE_SIGNUP,
        'trial_ends_at' => $trialEndsAt,
    ]);
    $storedEntitlement = $entitlement->fresh();

    expect($workspace->fresh()->entitlement)->not->toBeNull()
        ->and($entitlement->canWrite())->toBeTrue()
        ->and($entitlement->isReadOnly())->toBeFalse()
        ->and($entitlement->includedClinicalSeats())->toBe(1)
        ->and($entitlement->additionalClinicalSeats())->toBe(0)
        ->and($storedEntitlement->trial_ends_at->getTimestamp())->toBe($trialEndsAt->getTimestamp());
});

it('calculates clinic active seats beyond the two included doctors', function () {
    $clinic = Workspace::factory()->clinic()->create();

    Doctor::factory()->count(3)->create()->each(function (Doctor $doctor) use ($clinic): void {
        WorkspaceMember::query()->create([
            'workspace_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'role' => 'doctor',
            'roles' => ['doctor'],
            'status' => 'active',
        ]);
    });

    $entitlement = WorkspaceEntitlement::query()->create([
        'workspace_id' => $clinic->id,
        'plan_code' => WorkspaceEntitlement::PLAN_CLINIC,
        'state' => WorkspaceEntitlement::STATE_ACTIVE,
        'source' => WorkspaceEntitlement::SOURCE_OPERATOR,
    ]);

    expect($entitlement->includedClinicalSeats())->toBe(2)
        ->and($entitlement->additionalClinicalSeats())->toBe(1);
});

it('makes a read-only entitlement unable to write', function () {
    $entitlement = WorkspaceEntitlement::query()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'plan_code' => WorkspaceEntitlement::PLAN_PERSONAL,
        'state' => WorkspaceEntitlement::STATE_READ_ONLY,
        'source' => WorkspaceEntitlement::SOURCE_OPERATOR,
        'access_ends_at' => CarbonImmutable::parse('2026-09-30 00:00:00', 'America/Guayaquil'),
    ]);

    expect($entitlement->isReadOnly())->toBeTrue()
        ->and($entitlement->canWrite())->toBeFalse();
});

it('provides explicit entitlement factory states', function () {
    $trial = WorkspaceEntitlement::factory()->trial()->make();
    $active = WorkspaceEntitlement::factory()->active()->make();
    $readOnly = WorkspaceEntitlement::factory()->readOnly()->make();

    expect($trial->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($trial->trial_ends_at)->not->toBeNull()
        ->and($active->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($active->trial_ends_at)->toBeNull()
        ->and($readOnly->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY)
        ->and($readOnly->access_ends_at)->not->toBeNull();
});

it('records an entitlement event without creating fiscal records', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::query()->create([
        'workspace_id' => $workspace->id,
        'plan_code' => WorkspaceEntitlement::PLAN_CLINIC,
        'state' => WorkspaceEntitlement::STATE_ACTIVE,
        'source' => WorkspaceEntitlement::SOURCE_OPERATOR,
    ]);
    $actor = User::factory()->create();

    $event = WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'actor_user_id' => $actor->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active', 'note' => 'Revisión manual'],
    ]);

    expect($event->actor->is($actor))->toBeTrue()
        ->and($event->action)->toBe('operator_set_active')
        ->and($event->before)->toBe(['state' => 'trial'])
        ->and($event->after)->toBe(['state' => 'active', 'note' => 'Revisión manual']);

    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('payments', 0);
});

it('rejects entitlement event mutations through the query builder', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $event = WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active'],
    ]);

    expect(fn () => DB::transaction(function () use ($event): int {
        return DB::table('workspace_entitlement_events')
            ->where('id', $event->id)
            ->update(['action' => 'rewritten']);
    }))->toThrow(QueryException::class);

    expect(fn () => DB::transaction(function () use ($event): int {
        return DB::table('workspace_entitlement_events')
            ->where('id', $event->id)
            ->delete();
    }))->toThrow(QueryException::class);
});

it('rejects truncating entitlement events at the database', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $event = WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active'],
    ]);

    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE TABLE workspace_entitlement_events')))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('workspace_entitlement_events', ['id' => $event->id]);
});

it('retains an entitlement event actor by restricting actor deletion', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $actor = User::factory()->create();
    $event = WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'actor_user_id' => $actor->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active'],
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $actor->id)->delete()))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'id' => $event->id,
        'actor_user_id' => $actor->id,
    ]);
});

it('retains entitlement event parents instead of cascading their deletion', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->create([
        'workspace_id' => $workspace->id,
    ]);
    $event = WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active'],
    ]);

    expect(fn () => DB::transaction(fn () => DB::table('workspace_entitlements')->where('id', $entitlement->id)->delete()))
        ->toThrow(QueryException::class);

    expect(fn () => DB::transaction(fn () => DB::table('workspaces')->where('id', $workspace->id)->delete()))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('workspace_entitlement_events', ['id' => $event->id]);
});

it('rejects entitlement events that cross workspace boundaries', function () {
    $entitlementWorkspace = Workspace::factory()->clinic()->create();
    $otherWorkspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->create([
        'workspace_id' => $entitlementWorkspace->id,
    ]);

    expect(fn () => DB::transaction(fn () => WorkspaceEntitlementEvent::query()->create([
        'workspace_id' => $otherWorkspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'action' => 'operator_set_active',
        'before' => ['state' => 'trial'],
        'after' => ['state' => 'active'],
    ])))->toThrow(QueryException::class);
});

it('rejects invalid entitlement plan, state, and source values at the database', function () {
    foreach ([
        'plan_code' => 'unknown',
        'state' => 'paused',
        'source' => 'billing',
    ] as $column => $value) {
        $attributes = [
            'workspace_id' => Workspace::factory()->create()->id,
            'plan_code' => WorkspaceEntitlement::PLAN_PERSONAL,
            'state' => WorkspaceEntitlement::STATE_TRIAL,
            'source' => WorkspaceEntitlement::SOURCE_SIGNUP,
        ];
        $attributes[$column] = $value;

        expect(fn () => DB::transaction(fn () => WorkspaceEntitlement::query()->create($attributes)))
            ->toThrow(QueryException::class);
    }
});

it('rejects zero and negative clinical seat limits at the database', function () {
    foreach ([0, -1] as $clinicalSeatLimit) {
        expect(fn () => DB::transaction(fn () => WorkspaceEntitlement::query()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'plan_code' => WorkspaceEntitlement::PLAN_CLINIC,
            'state' => WorkspaceEntitlement::STATE_ACTIVE,
            'source' => WorkspaceEntitlement::SOURCE_OPERATOR,
            'clinical_seat_limit' => $clinicalSeatLimit,
        ])))->toThrow(QueryException::class);
    }
});

it('does not treat an invalid plan as a clinic when evaluating the seat limit', function () {
    $entitlement = WorkspaceEntitlement::factory()->make([
        'workspace_id' => Workspace::factory()->create()->id,
        'plan_code' => 'unknown',
    ]);

    expect(fn () => $entitlement->isSeatLimitReached())->toThrow(LogicException::class);
});

it('persists the default campaign and gives a new doctor exactly thirty days', function () {
    $startedAt = CarbonImmutable::parse('2026-08-03 09:15:00', 'America/Guayaquil');
    $this->travelTo($startedAt);

    try {
        $user = User::factory()->create(['account_type' => 'doctor']);
        $workspace = Workspace::factory()->create([
            'type' => 'personal',
            'owner_user_id' => $user->id,
        ]);

        $campaign = app(TrialCampaignService::class);
        $entitlements = app(WorkspaceEntitlementService::class);

        $grant = $campaign->grantForDoctor($user);
        $entitlement = $entitlements->createSignupEntitlement($workspace, $grant);
        $setting = PlatformSetting::query()->where('key', 'trial_campaign')->sole();

        // Whole Ecuadorian day, not the 09:15 the doctor happened to sign up at.
        $expectedEnd = $startedAt->addDays(30)->endOfDay();

        expect($grant->started_at->getTimestamp())->toBe($startedAt->getTimestamp())
            ->and($grant->ends_at->getTimestamp())->toBe($expectedEnd->getTimestamp())
            ->and($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_PERSONAL)
            ->and($entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
            ->and($entitlement->trial_ends_at->getTimestamp())->toBe($expectedEnd->getTimestamp())
            ->and($setting->value)->toBe([
                'trial_duration_days' => 30,
                'trial_enabled_until' => '2026-12-31',
            ]);
    } finally {
        $this->travelBack();
    }
});

it('grants a clinic administrator only while the persisted campaign is enabled', function () {
    $enabledAt = CarbonImmutable::parse('2026-12-31 23:59:59', 'America/Guayaquil');
    $this->travelTo($enabledAt);

    try {
        $campaign = app(TrialCampaignService::class);
        $clinicAdmin = User::factory()->create(['account_type' => 'staff']);

        $grant = $campaign->grantForClinicAdmin($clinicAdmin);

        expect($grant)->not->toBeNull()
            ->and($grant->ends_at->getTimestamp())->toBe($enabledAt->addDays(30)->endOfDay()->getTimestamp());

        $this->travelTo($enabledAt->addSecond());
        $lateRegistrant = User::factory()->create(['account_type' => 'doctor']);

        expect($campaign->grantForDoctor($lateRegistrant))->toBeNull();
        $this->assertDatabaseCount('account_trial_grants', 1);
    } finally {
        $this->travelBack();
    }
});

it('shares a first clinic deadline and makes used or expired clinic trials read only without another grant', function () {
    $startedAt = CarbonImmutable::parse('2026-08-03 10:00:00', 'America/Guayaquil');
    $this->travelTo($startedAt);

    try {
        $campaign = app(TrialCampaignService::class);
        $entitlements = app(WorkspaceEntitlementService::class);
        $user = User::factory()->create(['account_type' => 'doctor']);
        $grant = $campaign->grantForDoctor($user);

        $this->travelTo($startedAt->addDays(12));
        $firstClinic = Workspace::factory()->clinic()->create(['owner_user_id' => $user->id]);
        $firstEntitlement = $entitlements->attachFirstClinic($firstClinic, $grant);
        $claimedAt = $grant->fresh()->first_clinic_claimed_at;

        $secondClinic = Workspace::factory()->clinic()->create(['owner_user_id' => $user->id]);
        $secondEntitlement = $entitlements->attachFirstClinic($secondClinic, $grant);

        $expiredUser = User::factory()->create(['account_type' => 'doctor']);
        $expiredGrant = AccountTrialGrant::query()->create([
            'user_id' => $expiredUser->id,
            'mode' => 'doctor_personal_first_clinic',
            'started_at' => $startedAt->subDays(30),
            'ends_at' => $startedAt->subSecond(),
        ]);
        $expiredClinic = Workspace::factory()->clinic()->create(['owner_user_id' => $expiredUser->id]);
        $expiredEntitlement = $entitlements->attachFirstClinic($expiredClinic, $expiredGrant);

        expect($firstEntitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
            ->and($firstEntitlement->trial_ends_at->getTimestamp())->toBe($startedAt->addDays(30)->endOfDay()->getTimestamp())
            ->and($claimedAt)->not->toBeNull()
            ->and($secondEntitlement->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY)
            ->and($secondEntitlement->trial_ends_at)->toBeNull()
            ->and($expiredEntitlement->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY)
            ->and($expiredEntitlement->trial_ends_at)->toBeNull()
            ->and($expiredGrant->fresh()->first_clinic_claimed_at)->toBeNull()
            ->and($grant->fresh()->first_clinic_claimed_at->getTimestamp())->toBe($claimedAt->getTimestamp());

        $this->assertDatabaseCount('account_trial_grants', 2);
    } finally {
        $this->travelBack();
    }
});

it('persists the finite grant modes for self-service doctors and clinic administrators', function () {
    $campaign = app(TrialCampaignService::class);
    $doctor = User::factory()->create(['account_type' => 'doctor']);
    $clinicAdmin = User::factory()->create(['account_type' => 'staff']);

    $doctorGrant = $campaign->grantForDoctor($doctor);
    $clinicAdminGrant = $campaign->grantForClinicAdmin($clinicAdmin);

    expect($doctorGrant?->mode)->toBe('doctor_personal_first_clinic')
        ->and($clinicAdminGrant?->mode)->toBe('clinic_admin_clinic_only');
});

it('does not issue the other self-service grant mode for an account type', function () {
    $campaign = app(TrialCampaignService::class);
    $doctor = User::factory()->create(['account_type' => 'doctor']);
    $staff = User::factory()->create(['account_type' => 'staff']);

    expect($campaign->grantForDoctor($staff))->toBeNull()
        ->and($campaign->grantForClinicAdmin($doctor))->toBeNull();
});

it('rejects an unknown grant mode at the database boundary', function () {
    $user = User::factory()->create();
    $now = now();

    expect(fn () => DB::transaction(fn () => DB::table('account_trial_grants')->insert([
        'user_id' => $user->id,
        'mode' => 'unbounded_trial',
        'started_at' => $now,
        'ends_at' => $now->copy()->addDays(30),
        'created_at' => $now,
        'updated_at' => $now,
    ])))->toThrow(QueryException::class);
});

it('does not allow a clinic-admin grant to create a personal entitlement', function () {
    $campaign = app(TrialCampaignService::class);
    $entitlements = app(WorkspaceEntitlementService::class);
    $clinicAdmin = User::factory()->create(['account_type' => 'staff']);
    $personalWorkspace = Workspace::factory()->create([
        'type' => 'personal',
        'owner_user_id' => $clinicAdmin->id,
    ]);

    $grant = $campaign->grantForClinicAdmin($clinicAdmin);

    expect(fn () => $entitlements->createSignupEntitlement($personalWorkspace, $grant))
        ->toThrow(LogicException::class);
});

it('allows a clinic-admin grant to claim only its first clinic entitlement', function () {
    $campaign = app(TrialCampaignService::class);
    $entitlements = app(WorkspaceEntitlementService::class);
    $clinicAdmin = User::factory()->create(['account_type' => 'staff']);
    $grant = $campaign->grantForClinicAdmin($clinicAdmin);
    $firstClinic = Workspace::factory()->clinic()->create(['owner_user_id' => $clinicAdmin->id]);
    $secondClinic = Workspace::factory()->clinic()->create(['owner_user_id' => $clinicAdmin->id]);

    $firstEntitlement = $entitlements->attachFirstClinic($firstClinic, $grant);
    $secondEntitlement = $entitlements->attachFirstClinic($secondClinic, $grant);

    expect($firstEntitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($firstEntitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC)
        ->and($secondEntitlement->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY);
});

it('does not grant trials to accounts created from accepted clinic invitations', function () {
    $campaign = app(TrialCampaignService::class);
    $clinic = Workspace::factory()->clinic()->create();
    $invitedDoctor = User::factory()->create(['account_type' => 'doctor']);
    $invitedStaff = User::factory()->create(['account_type' => 'staff']);

    foreach ([
        [$invitedDoctor, 'doctor'],
        [$invitedStaff, 'admin'],
    ] as [$user, $role]) {
        ClinicInvite::query()->create([
            'workspace_id' => $clinic->id,
            'email' => $user->email,
            'role' => $role,
            'token' => Str::random(64),
            'accepted_at' => now(),
        ]);
    }

    expect($campaign->grantForDoctor($invitedDoctor))->toBeNull()
        ->and($campaign->grantForClinicAdmin($invitedStaff))->toBeNull();
});

it('returns an existing grant when its holder later accepts a clinic invitation', function () {
    $campaign = app(TrialCampaignService::class);
    $clinic = Workspace::factory()->clinic()->create();
    $doctor = User::factory()->create(['account_type' => 'doctor']);
    $grant = $campaign->grantForDoctor($doctor);

    ClinicInvite::query()->create([
        'workspace_id' => $clinic->id,
        'email' => $doctor->email,
        'role' => 'doctor',
        'token' => Str::random(64),
        'accepted_at' => now(),
    ]);

    expect($campaign->grantForDoctor($doctor)?->is($grant))->toBeTrue();
});

it('enforces elapsed trials as read only before the expiry command persists the transition', function () {
    $now = CarbonImmutable::parse('2026-08-03 12:00:00', 'America/Guayaquil');
    $this->travelTo($now);

    try {
        $workspace = Workspace::factory()->create();
        $entitlement = WorkspaceEntitlement::factory()->trial()->create([
            'workspace_id' => $workspace->id,
            'trial_ends_at' => $now->subSecond(),
        ]);

        $access = app(WorkspaceEntitlementService::class)->effectiveAccess($workspace->fresh());

        expect($access)->toBe(WorkspaceEntitlementService::ACCESS_READ_ONLY)
            ->and($entitlement->fresh()->state)->toBe(WorkspaceEntitlement::STATE_TRIAL);
    } finally {
        $this->travelBack();
    }
});

it('fails closed when a workspace has no entitlement', function () {
    $workspace = Workspace::factory()->clinic()->create();

    expect(app(WorkspaceEntitlementService::class)->effectiveAccess($workspace))
        ->toBe(WorkspaceEntitlementService::ACCESS_READ_ONLY);
});

it('provisions legacy workspaces with an audited active entitlement', function () {
    $workspace = Workspace::factory()->clinic()->create();
    $operator = User::factory()->create();

    $entitlement = app(WorkspaceEntitlementService::class)
        ->provisionLegacyEntitlement($workspace, $operator);

    expect($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC)
        ->and($entitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($entitlement->source)->toBe(WorkspaceEntitlement::SOURCE_LEGACY_REVIEW);

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'actor_user_id' => $operator->id,
        'action' => 'legacy_entitlement_provisioned',
    ]);
});

it('provisions shared test workspaces as an audited signup trial', function () {
    // The shared helper now goes through the real Doctor lifecycle, so a test
    // doctor gets exactly what a registering doctor gets: an account grant and
    // a trial entitlement, both recorded.
    [, , $workspace] = makeDoctorWithWorkspace();
    $entitlement = $workspace->fresh('entitlement')->entitlement;

    expect($entitlement)->not->toBeNull()
        ->and($entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($entitlement->source)->toBe(WorkspaceEntitlement::SOURCE_SIGNUP)
        ->and($entitlement->canWrite())->toBeTrue();

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $workspace->id,
        'workspace_entitlement_id' => $entitlement->id,
        'action' => 'signup_trial_started',
    ]);
});

it('uses named, audited operator transitions for entitlement lifecycle changes', function () {
    $now = CarbonImmutable::parse('2026-08-03 14:00:00', 'America/Guayaquil');
    $this->travelTo($now);

    try {
        $workspace = Workspace::factory()->create();
        $entitlement = WorkspaceEntitlement::factory()->readOnly()->create([
            'workspace_id' => $workspace->id,
        ]);
        $actor = User::factory()->create();
        $service = app(WorkspaceEntitlementService::class);

        $active = $service->activate($entitlement, $actor);
        $extended = $service->extend($active, $now->addDays(90), $actor);
        $readOnly = $service->setReadOnly($extended, $actor);
        $restored = $service->restore($readOnly, $actor, $now->addDays(120));

        expect($active->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
            ->and($extended->access_ends_at->getTimestamp())->toBe($now->addDays(90)->getTimestamp())
            ->and($readOnly->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY)
            ->and($restored->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
            ->and($restored->access_ends_at->getTimestamp())->toBe($now->addDays(120)->getTimestamp());

        $actions = WorkspaceEntitlementEvent::query()
            ->where('workspace_entitlement_id', $entitlement->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        expect($actions)->toBe([
            'operator_set_active',
            'operator_extend_access',
            'operator_set_read_only',
            'operator_restore_access',
        ]);

        $this->assertDatabaseCount('workspace_entitlement_events', 4);
        $this->assertDatabaseHas('workspace_entitlement_events', [
            'workspace_entitlement_id' => $entitlement->id,
            'actor_user_id' => $actor->id,
            'action' => 'operator_restore_access',
        ]);
    } finally {
        $this->travelBack();
    }
});
