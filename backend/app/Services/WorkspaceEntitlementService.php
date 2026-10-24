<?php

namespace App\Services;

use App\Models\AccountTrialGrant;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class WorkspaceEntitlementService
{
    public const ACCESS_FULL = 'full';

    public const ACCESS_READ_ONLY = 'read_only';

    public function __construct(
        private readonly TrialCampaignService $campaigns,
    ) {}

    /**
     * The single entry point for a personal workspace created by the Doctor
     * lifecycle. A workspace without an entitlement resolves to read-only, so
     * this must never return without one: if the account has no grant yet —
     * a doctor invited into someone else's clinic, or an account that predates
     * the rollout — it issues the grant instead of silently locking them out.
     */
    public function provisionPersonalWorkspace(Workspace $workspace, ?User $owner = null): WorkspaceEntitlement
    {
        return DB::transaction(function () use ($workspace, $owner): WorkspaceEntitlement {
            $existing = $this->lockedEntitlement($workspace);

            if ($existing) {
                return $existing;
            }

            $owner ??= $workspace->owner_user_id
                ? User::query()->find($workspace->owner_user_id)
                : null;

            $grant = $this->resolveGrantFor($owner);

            if ($grant?->allowsPersonalEntitlement()) {
                return $this->createSignupEntitlementForGrant(
                    $workspace,
                    $grant,
                    $grant->ends_at->greaterThan(now()),
                );
            }

            // The campaign is closed or the account cannot hold a personal
            // trial. Record that explicitly rather than leaving a null
            // entitlement that every read would interpret as read-only.
            return $this->createEntitlementWithoutGrant($workspace);
        });
    }

    public function createSignupEntitlement(Workspace $workspace, AccountTrialGrant $grant): WorkspaceEntitlement
    {
        if (! $workspace->isPersonal()) {
            throw new LogicException('Signup entitlements are only created for personal workspaces.');
        }

        return DB::transaction(function () use ($workspace, $grant): WorkspaceEntitlement {
            $existing = $this->lockedEntitlement($workspace);

            if ($existing) {
                return $existing;
            }

            $lockedGrant = $this->lockedGrant($grant);
            $this->assertWorkspaceBelongsToGrant($workspace, $lockedGrant);

            if (! $lockedGrant->allowsPersonalEntitlement()) {
                throw new LogicException('This account trial grant cannot create personal workspace entitlements.');
            }

            return $this->createSignupEntitlementForGrant(
                $workspace,
                $lockedGrant,
                $lockedGrant->ends_at->greaterThan(now()),
            );
        });
    }

    public function attachFirstClinic(Workspace $workspace, AccountTrialGrant $grant): WorkspaceEntitlement
    {
        if (! $workspace->isClinic()) {
            throw new LogicException('First clinic trials are only available to clinic workspaces.');
        }

        return DB::transaction(function () use ($workspace, $grant): WorkspaceEntitlement {
            $existing = $this->lockedEntitlement($workspace);

            if ($existing) {
                return $existing;
            }

            $lockedGrant = $this->lockedGrant($grant);
            $this->assertWorkspaceBelongsToGrant($workspace, $lockedGrant);

            if (! $lockedGrant->allowsFirstClinicEntitlement()) {
                throw new LogicException('This account trial grant cannot create clinic workspace entitlements.');
            }

            $canClaimFirstClinic = $lockedGrant->first_clinic_claimed_at === null
                && $lockedGrant->ends_at->greaterThan(now());

            if ($canClaimFirstClinic) {
                $lockedGrant->update(['first_clinic_claimed_at' => now()]);
            }

            return $this->createSignupEntitlementForGrant($workspace, $lockedGrant, $canClaimFirstClinic);
        });
    }

    public function effectiveAccess(Workspace $workspace): string
    {
        $entitlement = $workspace->entitlement;

        if ($entitlement === null) {
            return self::ACCESS_READ_ONLY;
        }

        if ($entitlement->state === WorkspaceEntitlement::STATE_TRIAL
            && $entitlement->trial_ends_at?->isPast()) {
            return self::ACCESS_READ_ONLY;
        }

        return $entitlement->canWrite()
            ? self::ACCESS_FULL
            : self::ACCESS_READ_ONLY;
    }

    /**
     * Clinical seat usage for a workspace, in the terms the pricing model uses:
     * what the plan includes, how many doctors are actually attending, and how
     * many of those are billed as extras.
     *
     * @return array{included: ?int, active_doctors: int, additional: int}
     */
    public function seatSummary(Workspace $workspace): array
    {
        $entitlement = $workspace->entitlement;

        if ($entitlement === null) {
            return ['included' => 0, 'active_doctors' => 0, 'additional' => 0];
        }

        return [
            'included' => $entitlement->includedClinicalSeats(),
            'active_doctors' => $workspace->activeDoctors()->count(),
            'additional' => $entitlement->additionalClinicalSeats(),
        ];
    }

    /**
     * Whether the same owner runs both a personal consulting room and a clinic,
     * which is what the bundle price describes. Informational only: there is no
     * payment engine, so nothing is charged or recorded from this.
     */
    public function bundleEligible(Workspace $workspace): bool
    {
        if ($workspace->owner_user_id === null) {
            return false;
        }

        $plans = Workspace::query()
            ->where('owner_user_id', $workspace->owner_user_id)
            ->whereHas('entitlement')
            ->with('entitlement')
            ->get()
            ->map(fn (Workspace $owned): ?string => $owned->entitlement?->plan_code)
            ->filter()
            ->unique();

        return $plans->contains(WorkspaceEntitlement::PLAN_PERSONAL)
            && $plans->contains(WorkspaceEntitlement::PLAN_CLINIC);
    }

    /**
     * Monthly recurring revenue, in USD.
     *
     * Counts only `active` entitlements: a trial bills nothing yet and a
     * read-only space has stopped billing, so folding either into MRR would
     * report money that does not exist.
     *
     * Aggregated per owner, never per workspace. An owner running a consulting
     * room and a clinic pays the USD 30 bundle, not USD 15 + 21 = 36 — summing
     * row by row would overstate revenue by 20% in exactly the case the bundle
     * is meant to reward.
     */
    public function monthlyRecurringRevenueUsd(): int
    {
        $workspaces = Workspace::query()
            ->whereHas('entitlement', fn ($query) => $query->where('state', WorkspaceEntitlement::STATE_ACTIVE))
            ->with('entitlement')
            ->get();

        return $workspaces
            ->groupBy(fn (Workspace $workspace): string => (string) ($workspace->owner_user_id ?? "w{$workspace->id}"))
            ->sum(fn ($owned): int => $this->ownerMonthlyPriceUsd($owned));
    }

    /**
     * What one owner pays a month across everything they run.
     *
     * The bundle is expressed as a discount rather than a replacement price, so
     * additional doctors and any second clinic keep their own price instead of
     * disappearing into a flat figure.
     *
     * @param  Collection<int, Workspace>  $owned
     */
    private function ownerMonthlyPriceUsd($owned): int
    {
        $total = $owned->sum(fn (Workspace $workspace): int => $workspace->entitlement?->monthlyPriceUsd() ?? 0);

        $hasPersonal = $owned->contains(fn (Workspace $w): bool => $w->entitlement?->plan_code === WorkspaceEntitlement::PLAN_PERSONAL);
        $hasClinic = $owned->contains(fn (Workspace $w): bool => $w->entitlement?->plan_code === WorkspaceEntitlement::PLAN_CLINIC);

        if ($hasPersonal && $hasClinic) {
            $total -= WorkspaceEntitlement::PRICE_PERSONAL
                + WorkspaceEntitlement::PRICE_CLINIC
                - WorkspaceEntitlement::PRICE_BUNDLE;
        }

        return max($total, 0);
    }

    public function provisionLegacyEntitlement(
        Workspace $workspace,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return DB::transaction(function () use ($workspace, $actor, $note): WorkspaceEntitlement {
            $existing = $this->lockedEntitlement($workspace);

            if ($existing) {
                return $existing;
            }

            $entitlement = WorkspaceEntitlement::query()->create([
                'workspace_id' => $workspace->id,
                'plan_code' => $this->planCodeFor($workspace),
                'state' => WorkspaceEntitlement::STATE_ACTIVE,
                'source' => WorkspaceEntitlement::SOURCE_LEGACY_REVIEW,
                'clinical_seat_limit' => $this->seatLimitFor($workspace),
            ]);

            $this->recordEvent($entitlement, 'legacy_entitlement_provisioned', $actor, null, $note);

            return $entitlement;
        });
    }

    public function activate(
        WorkspaceEntitlement $entitlement,
        ?User $actor = null,
        ?DateTimeInterface $accessEndsAt = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return $this->transition(
            $entitlement,
            [
                'state' => WorkspaceEntitlement::STATE_ACTIVE,
                'trial_ends_at' => null,
                'access_ends_at' => $this->deadline($accessEndsAt),
            ],
            'operator_set_active',
            $actor,
            $note,
        );
    }

    public function extend(
        WorkspaceEntitlement $entitlement,
        DateTimeInterface $accessEndsAt,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return $this->transition(
            $entitlement,
            ['access_ends_at' => $this->deadline($accessEndsAt)],
            'operator_extend_access',
            $actor,
            $note,
        );
    }

    public function extendTrial(
        WorkspaceEntitlement $entitlement,
        DateTimeInterface $trialEndsAt,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return $this->transition(
            $entitlement,
            [
                'state' => WorkspaceEntitlement::STATE_TRIAL,
                'trial_ends_at' => $this->deadline($trialEndsAt),
                'access_ends_at' => null,
            ],
            'operator_extend_trial',
            $actor,
            $note,
        );
    }

    public function setReadOnly(
        WorkspaceEntitlement $entitlement,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return $this->transition(
            $entitlement,
            ['state' => WorkspaceEntitlement::STATE_READ_ONLY],
            'operator_set_read_only',
            $actor,
            $note,
        );
    }

    public function restore(
        WorkspaceEntitlement $entitlement,
        ?User $actor = null,
        ?DateTimeInterface $accessEndsAt = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return $this->transition(
            $entitlement,
            [
                'state' => WorkspaceEntitlement::STATE_ACTIVE,
                'trial_ends_at' => null,
                'access_ends_at' => $this->deadline($accessEndsAt),
            ],
            'operator_restore_access',
            $actor,
            $note,
        );
    }

    /**
     * Give a clinic room for more attending doctors than its plan includes —
     * the USD 7 per extra seat case — or tighten it back.
     */
    public function setSeatLimit(
        WorkspaceEntitlement $entitlement,
        int $seats,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        if ($entitlement->isUnlimited()) {
            throw new LogicException('An unlimited clinic has no doctor seat limit. Change its plan first.');
        }

        return $this->transition(
            $entitlement,
            ['clinical_seat_limit' => $seats],
            'operator_set_seat_limit',
            $actor,
            $note,
        );
    }

    /**
     * Switch a clinic between its standard seat-based plan and manual unlimited
     * access. A personal workspace is never converted into a clinic: the owner
     * creates a separate clinic and keeps the clinical history isolated.
     */
    public function changePlan(
        WorkspaceEntitlement $entitlement,
        string $plan,
        ?User $actor = null,
        ?string $note = null,
    ): WorkspaceEntitlement {
        if (! $entitlement->workspace->isClinic()
            || ! in_array($plan, [WorkspaceEntitlement::PLAN_CLINIC, WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED], true)) {
            throw new LogicException('Only clinic workspaces can switch between standard and unlimited plans.');
        }

        if ($entitlement->plan_code === $plan) {
            return $entitlement;
        }

        return $this->transition(
            $entitlement,
            [
                'plan_code' => $plan,
                'clinical_seat_limit' => $plan === WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED
                    ? null
                    : WorkspaceEntitlement::DEFAULT_CLINIC_SEATS,
            ],
            'operator_changed_plan',
            $actor,
            $note,
        );
    }

    /**
     * Lets an ACCOUNT create a clinic of its own — the operator's "permiso para
     * crear clínicas", worked from the user, not from a workspace.
     *
     * A clinic is created by claiming the account's one included clinic trial
     * (`ClinicService::claimableClinicGrant`). It fails when the account has no
     * grant, already used its clinic, or the grant expired. This reopens it:
     * the claim is handed back and the deadline pushed out (never pulled in —
     * the grant's date also tells how long the account's trial lasts). An
     * account that predates the grant, or whose campaign closed, gets one
     * directly: an operator deciding this is deliberate, unlike self-signup.
     */
    public function allowClinicCreation(
        User $user,
        ?DateTimeInterface $endsAt = null,
        ?User $actor = null,
        ?string $note = null,
    ): AccountTrialGrant {
        return DB::transaction(function () use ($user, $endsAt, $actor, $note): AccountTrialGrant {
            $mode = $this->campaigns->modeFor($user);

            if ($mode === null) {
                throw new LogicException('Only doctor and clinic-administrator accounts can create a clinic.');
            }

            $deadline = $this->deadline(
                $endsAt ?? CarbonImmutable::now(config('app.timezone'))->addDays(30)
            )->endOfDay();

            $grant = AccountTrialGrant::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($grant) {
                $grant->update([
                    'ends_at' => $grant->ends_at->greaterThan($deadline) ? $grant->ends_at : $deadline,
                    'first_clinic_claimed_at' => null,
                ]);
            } else {
                $grant = AccountTrialGrant::query()->create([
                    'user_id' => $user->id,
                    'mode' => $mode,
                    'started_at' => now(),
                    'ends_at' => $deadline,
                ]);
            }

            // The grant is account-level: the audit entry goes on the account's
            // own workspace when it has one.
            $entitlement = $user->ownedWorkspaces()->with('entitlement')->get()
                ->map(fn (Workspace $w) => $w->entitlement)
                ->filter()
                ->first();

            if ($entitlement) {
                $this->recordEvent($entitlement, 'operator_issued_account_grant', $actor, null, $note);
            }

            return $grant->fresh();
        });
    }

    /**
     * Put a usable trial back on an account whose one included trial is spent.
     *
     * Without this there is no way out of an expired trial at all: the operator
     * actions all work on entitlements of workspaces that already exist, and
     * `ClinicService` refuses a clinic to an account with no claimable grant.
     * The grant is account-level, so the audit entry is written against the
     * workspace the operator acted from.
     */
    public function issueAccountTrialGrant(
        Workspace $workspace,
        DateTimeInterface $endsAt,
        ?User $actor = null,
        ?string $note = null,
    ): AccountTrialGrant {
        return DB::transaction(function () use ($workspace, $endsAt, $actor, $note): AccountTrialGrant {
            $owner = $workspace->owner_user_id
                ? User::query()->find($workspace->owner_user_id)
                : null;

            if (! $owner) {
                throw new LogicException('This workspace has no owner account to grant a trial to.');
            }

            $mode = $this->campaigns->modeFor($owner);

            if ($mode === null) {
                throw new LogicException('Only doctor and clinic-administrator accounts can hold a trial grant.');
            }

            $grant = $owner->accountTrialGrant ?? $this->campaigns->ensureGrant($owner, $mode);

            if (! $grant) {
                throw new LogicException('The trial campaign is closed, so no grant could be issued.');
            }

            $grant->update([
                'ends_at' => $this->deadline($endsAt),
                // Hand back the included clinic too: an operator reopening a
                // trial means the account should be able to use it fully.
                'first_clinic_claimed_at' => null,
            ]);

            $entitlement = $workspace->entitlement;

            if ($entitlement) {
                $this->recordEvent($entitlement, 'operator_issued_account_grant', $actor, null, $note);
            }

            return $grant->fresh();
        });
    }

    /**
     * Record what a clinic paid us, and optionally push its access to the end
     * of the period that payment covers.
     *
     * Money and access stay separate models on purpose, but they move together
     * here: recording the payment and forgetting to extend the access is the
     * mistake this panel exists to prevent, and it is the operator's own money
     * on the line. The extension goes through the normal audited transition, so
     * the reason appears in the history like any other operator action.
     *
     * @param  array{amount: string|float|int, method: string, paid_at: DateTimeInterface, period_start: DateTimeInterface, period_end: DateTimeInterface, reference?: ?string, note?: ?string}  $data
     */
    public function recordPayment(
        Workspace $workspace,
        array $data,
        ?User $actor = null,
        bool $extendAccess = true,
    ): SubscriptionPayment {
        return DB::transaction(function () use ($workspace, $data, $actor, $extendAccess): SubscriptionPayment {
            $payment = SubscriptionPayment::query()->create([
                'workspace_id' => $workspace->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'paid_at' => $data['paid_at'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by_user_id' => $actor?->id,
            ]);

            if (! $extendAccess) {
                return $payment;
            }

            $entitlement = $workspace->entitlement
                ?? $this->provisionLegacyEntitlement($workspace, $actor, $data['note'] ?? null);

            $this->activate(
                $entitlement,
                $actor,
                $data['period_end'],
                trim(sprintf(
                    'Payment recorded: USD %s until %s. %s',
                    $data['amount'],
                    CarbonImmutable::instance($data['period_end'])->toDateString(),
                    $data['note'] ?? '',
                )),
            );

            return $payment;
        });
    }

    public function expireTrial(WorkspaceEntitlement $entitlement): bool
    {
        return DB::transaction(function () use ($entitlement): bool {
            $locked = WorkspaceEntitlement::query()
                ->lockForUpdate()
                ->find($entitlement->id);

            if (! $locked
                || $locked->state !== WorkspaceEntitlement::STATE_TRIAL
                || ! $locked->trial_ends_at?->isPast()) {
                return false;
            }

            $before = $this->snapshot($locked);
            $locked->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

            $this->recordEvent($locked, 'trial_expired', null, $before);

            return true;
        });
    }

    /** The account's grant, issuing one when the account never received it. */
    private function resolveGrantFor(?User $owner): ?AccountTrialGrant
    {
        if (! $owner) {
            return null;
        }

        $existing = $owner->accountTrialGrant;

        if ($existing) {
            return $existing;
        }

        $mode = $this->campaigns->modeFor($owner);

        // Read the grant back from the database: the freshly created instance
        // still holds the Guayaquil values the campaign computed with, while
        // the stored row is normalized to UTC. Entitlements must inherit the
        // stored deadline, not the in-memory one.
        return $mode ? $this->campaigns->ensureGrant($owner, $mode)?->fresh() : null;
    }

    private function createEntitlementWithoutGrant(Workspace $workspace): WorkspaceEntitlement
    {
        $entitlement = WorkspaceEntitlement::query()->create([
            'workspace_id' => $workspace->id,
            'plan_code' => $this->planCodeFor($workspace),
            'state' => WorkspaceEntitlement::STATE_READ_ONLY,
            'source' => WorkspaceEntitlement::SOURCE_SIGNUP,
            'clinical_seat_limit' => $this->seatLimitFor($workspace),
        ]);

        $this->recordEvent($entitlement, 'signup_trial_unavailable', null, null);

        return $entitlement;
    }

    private function planCodeFor(Workspace $workspace): string
    {
        return $workspace->isPersonal()
            ? WorkspaceEntitlement::PLAN_PERSONAL
            : WorkspaceEntitlement::PLAN_CLINIC;
    }

    /**
     * Grandfather the seats a clinic already uses. A clinic running with six
     * doctors must not be told it only has two the moment entitlements land —
     * the limit only ever constrains the *next* doctor it tries to add.
     */
    private function seatLimitFor(Workspace $workspace): ?int
    {
        if (! $workspace->isClinic()) {
            return null;
        }

        return max(
            WorkspaceEntitlement::DEFAULT_CLINIC_SEATS,
            $workspace->activeDoctors()->count(),
        );
    }

    private function createSignupEntitlementForGrant(
        Workspace $workspace,
        AccountTrialGrant $grant,
        bool $isTrial,
    ): WorkspaceEntitlement {
        $entitlement = WorkspaceEntitlement::query()->create([
            'workspace_id' => $workspace->id,
            'plan_code' => $this->planCodeFor($workspace),
            'state' => $isTrial
                ? WorkspaceEntitlement::STATE_TRIAL
                : WorkspaceEntitlement::STATE_READ_ONLY,
            'source' => WorkspaceEntitlement::SOURCE_SIGNUP,
            'trial_ends_at' => $isTrial ? $grant->ends_at : null,
            'clinical_seat_limit' => $this->seatLimitFor($workspace),
        ]);

        $this->recordEvent(
            $entitlement,
            $isTrial ? 'signup_trial_started' : 'signup_trial_unavailable',
            $grant->user,
            null,
        );

        return $entitlement;
    }

    private function transition(
        WorkspaceEntitlement $entitlement,
        array $attributes,
        string $action,
        ?User $actor,
        ?string $note = null,
    ): WorkspaceEntitlement {
        return DB::transaction(function () use ($entitlement, $attributes, $action, $actor, $note): WorkspaceEntitlement {
            $locked = WorkspaceEntitlement::query()
                ->lockForUpdate()
                ->find($entitlement->id);

            if (! $locked) {
                throw (new ModelNotFoundException)->setModel(WorkspaceEntitlement::class, [$entitlement->id]);
            }

            $before = $this->snapshot($locked);
            $locked->fill($attributes)->save();

            $this->recordEvent($locked, $action, $actor, $before, $note);

            return $locked->fresh();
        });
    }

    private function lockedEntitlement(Workspace $workspace): ?WorkspaceEntitlement
    {
        return WorkspaceEntitlement::query()
            ->where('workspace_id', $workspace->id)
            ->lockForUpdate()
            ->first();
    }

    private function lockedGrant(AccountTrialGrant $grant): AccountTrialGrant
    {
        $lockedGrant = AccountTrialGrant::query()
            ->lockForUpdate()
            ->find($grant->id);

        if (! $lockedGrant) {
            throw (new ModelNotFoundException)->setModel(AccountTrialGrant::class, [$grant->id]);
        }

        return $lockedGrant;
    }

    private function assertWorkspaceBelongsToGrant(Workspace $workspace, AccountTrialGrant $grant): void
    {
        if ((int) $workspace->owner_user_id !== (int) $grant->user_id) {
            throw new LogicException('The workspace owner must match the account trial grant.');
        }
    }

    /**
     * @return array<string, int|string|null>
     */
    private function snapshot(WorkspaceEntitlement $entitlement): array
    {
        return [
            'plan_code' => $entitlement->plan_code,
            'state' => $entitlement->state,
            'source' => $entitlement->source,
            'trial_ends_at' => $entitlement->trial_ends_at?->toIso8601String(),
            'access_ends_at' => $entitlement->access_ends_at?->toIso8601String(),
            'clinical_seat_limit' => $entitlement->clinical_seat_limit,
        ];
    }

    /**
     * An operator-supplied deadline, expressed in the timezone the clinic
     * actually works in. Access is granted or withdrawn on Ecuadorian calendar
     * days, so "until the 17th" has to mean the whole 17th in Guayaquil.
     */
    private function deadline(?DateTimeInterface $date): ?CarbonImmutable
    {
        return $date === null
            ? null
            : CarbonImmutable::instance($date)->setTimezone(config('app.timezone'));
    }

    private function recordEvent(
        WorkspaceEntitlement $entitlement,
        string $action,
        ?User $actor,
        ?array $before,
        ?string $note = null,
    ): void {
        WorkspaceEntitlementEvent::query()->create([
            'workspace_id' => $entitlement->workspace_id,
            'workspace_entitlement_id' => $entitlement->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'before' => $before,
            'after' => array_filter([
                ...$this->snapshot($entitlement),
                'operator_note' => $note,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
