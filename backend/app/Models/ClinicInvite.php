<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email + role invitation to join a clinic. Redeemed by token during
 * registration. Non-doctor roles create a staff account; the `doctor` role
 * creates a real Doctor (medical license/specialty) — the same account a
 * doctor gets when self-joining via the workspace invite_code.
 */
class ClinicInvite extends Model
{
    /** Clinic roles that can be invited via this email-based flow. */
    public const ROLES = ['doctor', 'nurse', 'receptionist', 'billing', 'admin'];

    protected $fillable = [
        'workspace_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    /** Whether the invite can still be redeemed. */
    public function isRedeemable(): bool
    {
        return $this->accepted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
