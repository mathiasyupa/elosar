<?php

namespace App\Filament\SuperAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetSpanishLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('es');

        return $next($request);
    }
}
