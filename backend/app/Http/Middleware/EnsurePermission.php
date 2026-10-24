<?php

namespace App\Http\Middleware;

use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role-based authorization gate for the token-based API.
 *
 * Runs AFTER `auth:sanctum` + `workspace` (which bind the authenticated user and
 * the active workspace). Resolves the user's effective permissions in that
 * workspace via WorkspacePermissionService and 403s when the required permission
 * is missing. UI hiding is convenience only — this is the real enforcement.
 *
 * Usage: ->middleware('permission:records.view')
 */
class EnsurePermission
{
    public function __construct(
        private readonly ActiveWorkspace $activeWorkspace,
        private readonly WorkspacePermissionService $permissions,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();
        $workspace = $this->activeWorkspace->current();

        // Should never happen behind auth:sanctum + workspace, but fail closed.
        if (! $user || ! $workspace) {
            return $this->forbidden();
        }

        if (! $this->permissions->can($user, $workspace, $permission)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'No tienes permiso para realizar esta acción.',
        ], 403);
    }
}
