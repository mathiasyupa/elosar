<?php

namespace App\Models;

use Database\Factories\WorkspaceEntitlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WorkspaceEntitlement extends Model
{
    /** @use HasFactory<WorkspaceEntitlementFactory> */
    use HasFactory;

    public const PLAN_PERSONAL = 'personal';

    public const PLAN_CLINIC = 'clinic';

    public const PLAN_CLINIC_UNLIMITED = 'clinic_unlimited';

    public const STATE_TRIAL = 'trial';

    public const STATE_ACTIVE = 'active';

    public const STATE_READ_ONLY = 'read_only';

    public const SOURCE_SIGNUP = 'signup';

    public const SOURCE_LEGACY_REVIEW = 'legacy_review';

    public const SOURCE_OPERATOR = 'operator';

    /** Clinical doctors included in the USD 21 clinic plan before extras. */
    public const DEFAULT_CLINIC_SEATS = 2;

    /** Clinical doctors included in the USD 15 personal plan. */
    public const PERSONAL_SEATS = 1;

    /** Monthly price of a personal consulting room, in USD. */
    public const PRICE_PERSONAL = 15;

    /** Monthly price of a clinic with its included doctors, in USD. */
    public const PRICE_CLINIC = 21;

    /** Monthly price of each attending doctor beyond the included ones. */
    public const PRICE_ADDITIONAL_DOCTOR = 7;

    /**
     * Monthly price when the same owner runs a consulting room and a clinic.
     * Applied per owner, never per workspace — see WorkspaceEntitlementService.
     */
    public const PRICE_BUNDLE = 30;

    protected $fillable = [
        'workspace_id',
        'plan_code',
        'state',
        'source',
        'trial_ends_at',
        'access_ends_at',
        'clinical_seat_limit',
    ];

    protected $casts = [
        'trial_ends_at' => 'immutable_datetime',
        'access_ends_at' => 'immutable_datetime',
        'clinical_seat_limit' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkspaceEntitlementEvent::class);
    }

    public function canWrite(): bool
    {
        return in_array($this->state, [self::STATE_TRIAL, self::STATE_ACTIVE], true);
    }

    public function isReadOnly(): bool
    {
        return $this->state === self::STATE_READ_ONLY;
    }

    public function isUnlimited(): bool
    {
        return $this->plan_code === self::PLAN_CLINIC_UNLIMITED;
    }

    public function includedClinicalSeats(): ?int
    {
        return match ($this->plan_code) {
            self::PLAN_PERSONAL => self::PERSONAL_SEATS,
            self::PLAN_CLINIC => $this->clinical_seat_limit ?? self::DEFAULT_CLINIC_SEATS,
            self::PLAN_CLINIC_UNLIMITED => null,
            default => throw new LogicException("Workspace entitlement [{$this->id}] has an invalid plan_code [{$this->plan_code}]."),
        };
    }

    public function isSeatLimitReached(): bool
    {
        $includedSeats = $this->includedClinicalSeats();

        return $includedSeats !== null
            && $this->workspace->activeDoctors()->count() >= $includedSeats;
    }

    /**
     * What this workspace costs a month on its own, given the doctors actually
     * attending. Does NOT know about the bundle: two workspaces of the same
     * owner are priced together, which only the owner-level aggregate can see.
     */
    public function monthlyPriceUsd(): int
    {
        if ($this->plan_code === self::PLAN_PERSONAL) {
            return self::PRICE_PERSONAL;
        }

        // This operator-assigned access has no automatic price or billing.
        if ($this->isUnlimited()) {
            return 0;
        }

        return self::PRICE_CLINIC + ($this->additionalClinicalSeats() * self::PRICE_ADDITIONAL_DOCTOR);
    }

    public function additionalClinicalSeats(): int
    {
        if ($this->isUnlimited()) {
            return 0;
        }

        $activeDoctors = $this->workspace->activeDoctors()->count();

        return max($activeDoctors - $this->includedClinicalSeats(), 0);
    }
}
