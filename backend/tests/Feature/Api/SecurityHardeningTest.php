<?php

use App\Models\Patient;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

/** Headers that select the active workspace for a tenant API request. */
function wsHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// A — Rate limiting on credential endpoints
// ─────────────────────────────────────────────

it('throttles brute-force login attempts (429 after 5 tries)', function () {
    [, $user] = makeDoctorWithWorkspace();

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email, 'password' => "bad-$i",
        ])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'bad-6',
    ])->assertStatus(429);
})->group('security');

// ─────────────────────────────────────────────
// D — No account enumeration via the login response
// ─────────────────────────────────────────────

it('returns an identical error for unknown email and wrong password', function () {
    [, $user] = makeDoctorWithWorkspace();

    $unknown = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com', 'password' => 'whatever',
    ])->assertStatus(422)->json('errors.email');

    $wrong = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email, 'password' => 'wrong-password',
    ])->assertStatus(422)->json('errors.email');

    expect($wrong)->toEqual($unknown);
})->group('security');

// ─────────────────────────────────────────────
// E — Security response headers
// ─────────────────────────────────────────────

it('sets baseline security headers on API responses', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/workspaces')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
})->group('security');

// ─────────────────────────────────────────────
// I — Bounded personal_background
// ─────────────────────────────────────────────

it('rejects an oversized personal_background blob', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = minimalPatientData([
        'personal_background' => ['other' => ['note' => str_repeat('A', 25000)]],
    ]);

    $this->postJson('/api/v1/patients', $payload, wsHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('personal_background');
})->group('security');

it('accepts a normal nested personal_background', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = minimalPatientData([
        'personal_background' => [
            'allergies' => 'Penicilina',
            'habits'    => ['tobacco' => 'never', 'alcohol' => 'occasional'],
            'other'     => ['note' => 'Sin antecedentes relevantes'],
        ],
    ]);

    $this->postJson('/api/v1/patients', $payload, wsHeaders($ws->id))->assertCreated();
})->group('security');

// ─────────────────────────────────────────────
// J — CORS fails closed
// ─────────────────────────────────────────────

/**
 * Re-evaluates config/cors.php under a given environment. The point of these
 * tests is the DEFAULT: config/cors.php used to fall back to '*', so deleting
 * CORS_ALLOWED_ORIGINS in Railway would have opened the clinical API to every
 * origin with nothing to notice it.
 *
 * @param  array<string, string>  $env
 * @return array<string, mixed>
 */
function corsConfigWith(array $env): array
{
    $original = [];

    foreach ($env as $key => $value) {
        $original[$key] = $_ENV[$key] ?? null;
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    try {
        return require config_path('cors.php');
    } finally {
        foreach ($original as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}

it('allows no origin at all when the variable is missing in production', function () {
    $config = corsConfigWith(['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => '']);

    expect($config['allowed_origins'])->toBe([]);
})->group('security');

it('never resolves the allow-list to a wildcard', function () {
    $cases = [
        ['APP_ENV' => 'production', 'CORS_ALLOWED_ORIGINS' => ''],
        ['APP_ENV' => 'local', 'CORS_ALLOWED_ORIGINS' => ''],
    ];

    foreach ($cases as $env) {
        expect(corsConfigWith($env)['allowed_origins'])->not->toContain('*');
    }

    // …and neither does the configuration this suite actually booted with.
    expect(config('cors.allowed_origins'))->not->toContain('*');
})->group('security');

it('honours an explicitly configured origin list', function () {
    $config = corsConfigWith([
        'APP_ENV' => 'production',
        'CORS_ALLOWED_ORIGINS' => 'https://elosar.site, https://www.elosar.site',
    ]);

    expect($config['allowed_origins'])->toBe(['https://elosar.site', 'https://www.elosar.site']);
})->group('security');
