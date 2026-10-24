<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTemporaryPasswordChanged
{
    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'api/v1/auth/me',
        'api/v1/auth/change-temporary-password',
        'api/v1/auth/logout',
        'api/v1/auth/logout-all',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! in_array($request->path(), self::ALLOWED_PATHS, true)) {
            return response()->json([
                'message' => 'Cambia la clave temporal antes de usar Elosar.',
                'code' => 'temporary_password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
