<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-time exchange code for the /superadmin "Impersonate" action. Redeemed
 * exactly once, within 5 minutes, via Api\AuthController::exchangeImpersonation
 * — the actual Sanctum bearer token is only ever issued at redemption time,
 * never embedded in the magic link itself.
 */
class ImpersonationGrant extends Model
{
    protected $table = 'impersonation_grants';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'target_user_id',
        'actor_user_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    public function isRedeemable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
