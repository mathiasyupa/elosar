<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Append-only row-mutation audit, written by the `audit_row_change` PostgreSQL
 * trigger (see migration 2026_06_15_000003). Read-only from the application.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'record_id'   => 'integer',
        'user_id'     => 'integer',
        'old_data'    => 'array',
        'new_data'    => 'array',
        'occurred_at' => 'datetime',
    ];
}
