<?php

/*
|--------------------------------------------------------------------------
| Allowed origins — fail closed
|--------------------------------------------------------------------------
|
| This used to default to '*', which meant that deleting or misspelling
| CORS_ALLOWED_ORIGINS in Railway silently opened the clinical API to every
| origin on the internet, with nothing to notice it. A default that fails open
| is worse than no default.
|
| Now an unset variable resolves to an EMPTY list in production: cross-origin
| calls are refused until someone sets it. We deliberately do not throw and
| abort boot — a crash would also take down the Filament admin panel, which is
| exactly where you would go to diagnose it, and it would take the API offline
| for doctors already working. An empty allow-list breaks the browser app in
| the same way while leaving the server reachable and fixable.
|
| Outside production the local Vite dev servers are assumed, so a fresh clone
| runs without extra setup.
|
*/

$configured = trim((string) env('CORS_ALLOWED_ORIGINS', ''));

// Missing APP_ENV is treated as production: unknown environments fail closed.
$isProduction = env('APP_ENV', 'production') === 'production';

$allowedOrigins = match (true) {
    $configured !== '' => array_values(array_filter(array_map('trim', explode(',', $configured)))),
    $isProduction => [],
    default => ['http://localhost:5173', 'http://127.0.0.1:5173'],
};

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Token auth (Bearer) does not use cookies, so credentials stay disabled.
    'supports_credentials' => false,

];
