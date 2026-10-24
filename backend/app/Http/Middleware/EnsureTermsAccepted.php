<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTermsAccepted
{
    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'api/v1/auth/me',
        'api/v1/auth/accept-terms',
        'api/v1/auth/change-temporary-password',
        'api/v1/auth/logout',
        'api/v1/auth/logout-all',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->path(), self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user?->terms_version !== (string) config('lopdp.terms_version')) {
            return response()->json([
                'message' => 'Debes aceptar los términos y la política de privacidad vigentes para continuar.',
                'code' => 'terms_acceptance_required',
            ], 403);
        }

        return $next($request);
    }
}
