<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attributes database-trigger audit rows to the authenticated user.
 *
 * Sets the `app.audit_user_id` PostgreSQL runtime setting for the request's
 * connection; the audit_row_change() trigger reads it. Best-effort: it never
 * blocks the request if the setting cannot be applied.
 */
class SetAuditUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // Always set the GUC (empty string when unauthenticated) so a persistent
        // connection (Octane / pgbouncer session-pool) cannot attribute this
        // request's writes to a previous request's user.
        try {
            DB::statement("select set_config('app.audit_user_id', ?, false)", [(string) (Auth::id() ?? '')]);
        } catch (\Throwable) {
            // Attribution is best-effort; do not break the request.
        }

        return $next($request);
    }
}
