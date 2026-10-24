<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One-time code handing a verified Google identity from the OAuth callback to
 * medico-web. Redeemed via Api\GoogleAuthController::exchange — the Sanctum
 * token is only ever issued at redemption, never embedded in the redirect URL.
 *
 * @see \App\Models\ImpersonationGrant the same pattern, for /superadmin
 */
class GoogleAuthHandoff extends Model
{
    protected $table = 'google_auth_handoffs';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'google_id',
        'email',
        'name',
        'user_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'    => 'datetime',
    ];

    /**
     * Long enough to cover filling in the sign-up wizard (cédula, phone, city,
     * specialties), which is the slowest thing a handoff has to survive.
     */
    private const TTL_MINUTES = 30;

    public static function issueFor(string $googleId, string $email, ?string $name, ?User $user): self
    {
        return self::create([
            'code'       => Str::random(64),
            'google_id'  => $googleId,
            'email'      => $email,
            'name'       => $name,
            'user_id'    => $user?->id,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    public function isRedeemable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function markUsed(): void
    {
        $this->update(['used_at' => now()]);
    }

    /** No account behind this identity yet — it can only be used to sign up. */
    public function awaitsRegistration(): bool
    {
        return $this->user_id === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
