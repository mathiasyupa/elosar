<?php

namespace App\Http\Middleware;

use App\Support\ActiveWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stateless multi-tenant resolver for the token-based API.
 *
 * Each request must declare which workspace it operates on via the
 * `X-Workspace-Id` header. We verify the authenticated doctor actually has
 * access to it, then bind it on the per-request ActiveWorkspace singleton so
 * WorkspaceScope isolates every query automatically — no controller changes.
 *
 * Must run AFTER `auth:sanctum`: it relies on the authenticated user.
 */
class ResolveActiveWorkspace
{
    public function __construct(private readonly ActiveWorkspace $activeWorkspace)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Workspace-Id');

        if ($header === null || $header === '') {
            return $this->error('Falta el header X-Workspace-Id.', 400);
        }

        if (! ctype_digit((string) $header)) {
            return $this->error('X-Workspace-Id debe ser un identificador numérico.', 400);
        }

        $workspaceId = (int) $header;

        // available() lists the personal workspace + clinics the doctor belongs
        // to. Empty for users without a Doctor profile (e.g. system admins),
        // which correctly locks them out of the doctor API.
        $accessible = $this->activeWorkspace->available()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if (! $accessible->contains($workspaceId)) {
            return $this->error('No tienes acceso a este workspace.', 403);
        }

        $this->activeWorkspace->bind($workspaceId);

        return $next($request);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
