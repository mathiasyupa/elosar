<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Clinical-module gate derived from the authenticated doctor's approved
 * specialty capabilities. Workspace membership and records.* authorization run
 * alongside this middleware; this only answers whether this doctor is qualified
 * for the specialty-specific surface.
 *
 * Usage: ->middleware('specialty.capability:gyneco_obstetrics')
 */
class EnsureSpecialtyCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $doctor = $request->user()?->doctor;

        if (! $doctor?->hasSpecialtyCapability($capability)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'No tienes acceso a este módulo clínico.',
        ], 403);
    }
}
