<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-access log for sensitive clinical data (who viewed which record).
 * Written from the application layer via App\Support\AccessLogger.
 */
class AccessLog extends Model
{
    protected $table = 'access_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'subject_type',
        'subject_id',
        'action',
        'ip_address',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];
}
