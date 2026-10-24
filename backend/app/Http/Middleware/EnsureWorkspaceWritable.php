<?php

namespace App\Http\Middleware;

use App\Exceptions\WorkspaceReadOnlyException;
use App\Services\WorkspaceEntitlementService;
use App\Support\ActiveWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceWritable
{
    public function __construct(
        private readonly ActiveWorkspace $activeWorkspace,
        private readonly WorkspaceEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $workspace = $this->activeWorkspace->current();

        if ($workspace === null) {
            return response()->json([
                'message' => 'No tienes acceso a este workspace.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($this->entitlements->effectiveAccess($workspace) !== WorkspaceEntitlementService::ACCESS_FULL) {
            throw new WorkspaceReadOnlyException;
        }

        return $next($request);
    }
}
