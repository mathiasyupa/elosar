<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WorkspaceEntitlementEvent extends Model
{
    protected $fillable = [
        'workspace_id',
        'workspace_entitlement_id',
        'actor_user_id',
        'action',
        'before',
        'after',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Workspace entitlement events are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Workspace entitlement events are append-only.');
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(WorkspaceEntitlement::class, 'workspace_entitlement_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
