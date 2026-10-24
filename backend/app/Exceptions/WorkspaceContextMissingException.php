<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-scoped model is queried with no resolvable workspace.
 *
 * This is the fail-closed guard of the multi-tenant isolation layer: rather
 * than silently returning rows from EVERY tenant (the old fail-open behavior),
 * WorkspaceScope refuses to run the query.
 *
 * Legitimate cross-tenant contexts must opt in explicitly:
 *   - Authenticate a doctor (web/Filament) so the workspace resolves, or
 *   - bind a workspace statelessly: app(ActiveWorkspace::class)->bind($id)
 *     / runBound($id, fn () => ...)  (queued jobs, API requests), or
 *   - wrap deliberate cross-tenant access:
 *     app(ActiveWorkspace::class)->runUnguarded(fn () => ...)  (seeders, CLI,
 *     superadmin reporting).
 */
class WorkspaceContextMissingException extends RuntimeException
{
    public function __construct(string $model)
    {
        parent::__construct(sprintf(
            'No active workspace while querying the tenant-scoped model [%s]. '
            . 'Authenticate a doctor, bind a workspace via ActiveWorkspace::bind(), '
            . 'or wrap deliberate cross-tenant access in ActiveWorkspace::runUnguarded().',
            $model
        ));
    }
}
