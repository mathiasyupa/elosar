<?php

namespace App\Support;

use App\Models\AccessLog;
use Illuminate\Support\Facades\Auth;

/**
 * Records read access to sensitive clinical records (patient files, medical
 * records). Complements the write-audit trigger, which cannot capture reads.
 */
class AccessLogger
{
    public function log(string $subjectType, int $subjectId, string $action = 'view'): void
    {
        AccessLog::create([
            'user_id'      => Auth::id(),
            'workspace_id' => app(ActiveWorkspace::class)->id(),
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'action'       => $action,
            'ip_address'   => request()?->ip(),
            'occurred_at'  => now(),
        ]);
    }
}
