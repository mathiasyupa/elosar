<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for platform_admin "impersonate" (support login-as). Written
 * from the /superadmin panel whenever a short-lived token is issued for
 * another user.
 */
class ImpersonationLog extends Model
{
    protected $table = 'impersonation_logs';

    public $timestamps = false;

    protected $fillable = [
        'actor_user_id',
        'target_user_id',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
