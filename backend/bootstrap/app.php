<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSpecialtyCapability;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Middleware\EnsureTemporaryPasswordChanged;
use App\Http\Middleware\EnsureWorkspaceWritable;
use App\Http\Middleware\ResolveActiveWorkspace;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway (and any managed platform) terminates TLS at its proxy and
        // forwards HTTP to the container. Trust the proxy so Laravel reads
        // X-Forwarded-Proto and generates https:// URLs — otherwise Filament
        // server-rendered pages reference http:// assets that the browser
        // blocks as mixed content (panels render unstyled).
        $middleware->trustProxies(at: '*');

        // Blanket per-user/IP throttle + security headers on every API response.
        // Credential endpoints add a stricter `throttle:login` on top (see routes).
        $middleware->api(prepend: ['throttle:api']);
        $middleware->api(append: [SecurityHeaders::class]);

        // Resolves and binds the active workspace from the X-Workspace-Id header
        // for token-based API requests (stateless multi-tenant isolation).
        $middleware->alias([
            'workspace' => ResolveActiveWorkspace::class,
            'workspace.writable' => EnsureWorkspaceWritable::class,
            'permission' => EnsurePermission::class,
            'specialty.capability' => EnsureSpecialtyCapability::class,
            'terms.accepted' => EnsureTermsAccepted::class,
            'temporary_password.changed' => EnsureTemporaryPasswordChanged::class,
        ]);

        // Resolve the tenant, then authorize the role, and only then ask
        // whether the workspace may be written to. A caller without the
        // permission must still get a 403, not a commercial 423.
        $middleware->appendToPriorityList(ResolveActiveWorkspace::class, EnsurePermission::class);
        $middleware->appendToPriorityList(EnsurePermission::class, EnsureSpecialtyCapability::class);
        $middleware->appendToPriorityList(EnsurePermission::class, EnsureWorkspaceWritable::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report unhandled exceptions to Sentry. Without this the SDK is
        // installed and configured but captures nothing on its own — only the
        // explicit report() calls would arrive.
        //
        // Everything still passes through App\Support\SentryScrubber before it
        // leaves: no request body, no query string, no user beyond an id.
        Integration::handles($exceptions);
    })->create();
