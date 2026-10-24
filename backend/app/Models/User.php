<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'account_type', 'google_id', 'suspended_at', 'suspended_reason', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
/**
 * MustVerifyEmail is enforced at API login, not by Filament: a doctor cannot sign
 * in to the app until the address answers. Password recovery is only as good as
 * the address it sends to, so an unverified one is a lockout waiting to happen.
 *
 * Accounts created through an invitation are verified on the spot — the invite
 * token itself travelled to that address, which is the same proof.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Filament panel access restriction.
     * Only users with at least one assigned role can enter.
     * The super_admin gate bypass applies after this check.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        if ($panel->getId() === 'superadmin') {
            return $this->hasRole('platform_admin');
        }

        return $this->roles()->exists();
    }

    /** Whether a platform_admin has locked this account (login blocked everywhere). */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Clinical record (Doctor) linked to this user account.
     * Null when the User is not a doctor (e.g. system administrator).
     */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class, 'user_id');
    }

    /**
     * Operational profile for staff accounts (receptionist / nurse / billing).
     * Null when the User is a doctor.
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class, 'user_id');
    }

    /**
     * Clinic memberships (authoritative access + role table), keyed by user so it
     * covers both doctors and staff.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class, 'user_id');
    }

    public function accountTrialGrant(): HasOne
    {
        return $this->hasOne(AccountTrialGrant::class);
    }

    /**
     * Workspaces this account owns commercially — the personal consulting room
     * and any clinic it created. Membership in someone else's clinic is not
     * ownership and lives in `memberships()`.
     */
    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
    }

    /** Whether this account is a doctor (clinical) account. */
    public function isDoctor(): bool
    {
        return $this->account_type === 'doctor';
    }

    /** Whether this account is a non-clinical staff account. */
    public function isStaff(): bool
    {
        return $this->account_type === 'staff';
    }

    // ─────────────────────────────────────────────
    // TOTP multi-factor authentication (Filament)
    //
    // Mandatory on the superadmin panel: that account sees every clinic on the
    // platform, so a stolen password alone must not be enough. Optional for
    // doctors and clinic staff — forcing it on them would cost adoption without
    // a comparable blast radius.
    // ─────────────────────────────────────────────

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /** Shown next to the entry in the doctor's authenticator app. */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'onboarding_dismissed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'must_change_password' => 'boolean',
            'workspace_access_lock_id' => 'integer',
            // Encrypted at rest: a database dump must not yield working second
            // factors or usable recovery codes.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }
}
