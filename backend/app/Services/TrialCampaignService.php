<?php

namespace App\Services;

use App\Models\AccountTrialGrant;
use App\Models\ClinicInvite;
use App\Models\PlatformSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

class TrialCampaignService
{
    public const SETTING_KEY = 'trial_campaign';

    private const TIMEZONE = 'America/Guayaquil';

    /**
     * @var array{trial_duration_days: int, trial_enabled_until: string}
     */
    private const DEFAULTS = [
        'trial_duration_days' => 30,
        'trial_enabled_until' => '2026-12-31',
    ];

    public function grantForDoctor(User $user): ?AccountTrialGrant
    {
        if (! $user->isDoctor()) {
            return null;
        }

        return $this->createSignupGrant($user, AccountTrialGrant::MODE_DOCTOR_PERSONAL_FIRST_CLINIC);
    }

    public function grantForClinicAdmin(User $user): ?AccountTrialGrant
    {
        if (! $user->isStaff()) {
            return null;
        }

        return $this->createSignupGrant($user, AccountTrialGrant::MODE_CLINIC_ADMIN_CLINIC_ONLY);
    }

    /**
     * Issue the account grant for someone who did not arrive through public
     * signup: a doctor invited into another clinic, or an account that predates
     * the entitlement rollout. Their own personal workspace still deserves a
     * trial, so this deliberately skips the invitation check that
     * `grantForDoctor()` applies to public self-service registration.
     */
    public function ensureGrant(User $user, string $mode): ?AccountTrialGrant
    {
        return $this->createSignupGrant($user, $mode, honorInvitationCheck: false);
    }

    /** The grant mode that matches how this account signs in. */
    public function modeFor(User $user): ?string
    {
        return match (true) {
            $user->isDoctor() => AccountTrialGrant::MODE_DOCTOR_PERSONAL_FIRST_CLINIC,
            $user->isStaff() => AccountTrialGrant::MODE_CLINIC_ADMIN_CLINIC_ONLY,
            default => null,
        };
    }

    /**
     * @return array{trial_duration_days: int, trial_enabled_until: string}
     */
    public function settings(): array
    {
        $timestamp = now();

        PlatformSetting::query()->insertOrIgnore([
            'key' => self::SETTING_KEY,
            'value' => json_encode(self::DEFAULTS, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $setting = PlatformSetting::query()
            ->where('key', self::SETTING_KEY)
            ->firstOrFail();
        $value = $setting->value;

        if (! is_array($value)) {
            throw new LogicException('The trial campaign setting must be an object.');
        }

        $duration = filter_var($value['trial_duration_days'] ?? null, FILTER_VALIDATE_INT);
        $enabledUntil = $value['trial_enabled_until'] ?? null;

        if ($duration === false || $duration < 1 || ! is_string($enabledUntil)) {
            throw new LogicException('The trial campaign setting is invalid.');
        }

        $this->enabledUntil($enabledUntil);

        return [
            'trial_duration_days' => $duration,
            'trial_enabled_until' => $enabledUntil,
        ];
    }

    private function createSignupGrant(
        User $user,
        string $mode,
        bool $honorInvitationCheck = true,
    ): ?AccountTrialGrant {
        return DB::transaction(function () use ($user, $mode, $honorInvitationCheck): ?AccountTrialGrant {
            $existing = AccountTrialGrant::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($honorInvitationCheck && $this->wasCreatedFromAcceptedClinicInvitation($user)) {
                return null;
            }

            $settings = $this->settings();
            $now = now(self::TIMEZONE)->toImmutable();

            if ($now->greaterThan($this->enabledUntil($settings['trial_enabled_until']))) {
                return null;
            }

            return AccountTrialGrant::query()->create([
                'user_id' => $user->id,
                'mode' => $mode,
                'started_at' => $now,
                // Dies at the end of its last Guayaquil day, never at the
                // wall-clock minute of signup: whoever registered at 23:00 must
                // not lose write access mid-consultation on day 30.
                'ends_at' => $now->addDays($settings['trial_duration_days'])->endOfDay(),
            ]);
        });
    }

    private function wasCreatedFromAcceptedClinicInvitation(User $user): bool
    {
        return ClinicInvite::query()
            ->where('email', $user->email)
            ->whereNotNull('accepted_at')
            ->exists();
    }

    private function enabledUntil(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new LogicException('The trial campaign end date is invalid.');
        }

        return $date->endOfDay();
    }
}
