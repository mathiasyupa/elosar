<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read/manage view over Laravel's own `failed_jobs` table, surfaced in the
 * /superadmin System Health page (retry/delete). No app code writes here —
 * the queue worker does, via Laravel's failed-job handler.
 */
class FailedJob extends Model
{
    protected $table = 'failed_jobs';

    public $timestamps = false;

    protected $casts = [
        'failed_at' => 'datetime',
    ];
}
