<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/** Config a healthy deployment would have, so each test can break one thing. */
function healthyPreflightConfig(): void
{
    config([
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'app.debug' => false,
        'app.frontend_url' => 'https://elosar.site',
        'mail.default' => 'resend',
        'mail.from.address' => 'no-reply@elosar.site',
        'services.resend.key' => 're_test_key',
        'cors.allowed_origins' => ['https://elosar.site'],
    ]);
}

it('passes on a healthy environment', function () {
    healthyPreflightConfig();

    $this->artisan('elosar:preflight')->assertSuccessful();
});

it('fails when the mailer swallows everything', function () {
    healthyPreflightConfig();
    config(['mail.default' => 'log']);

    $this->artisan('elosar:preflight')
        ->expectsOutputToContain('MAIL_MAILER=log')
        ->assertFailed();
});

it('fails when the resend key is missing', function () {
    healthyPreflightConfig();
    config(['services.resend.key' => null]);

    $this->artisan('elosar:preflight')->assertFailed();
});

it('fails when the sender is still an example address', function () {
    healthyPreflightConfig();
    config(['mail.from.address' => 'hello@example.com']);

    $this->artisan('elosar:preflight')->assertFailed();
});

it('catches a seeded demo account still reachable with the published password', function () {
    healthyPreflightConfig();

    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
    ]);

    $this->artisan('elosar:preflight')
        ->expectsOutputToContain('test@example.com')
        ->expectsOutputToContain('password')
        ->assertFailed();
});

it('stops failing once the demo account is suspended', function () {
    healthyPreflightConfig();

    // Still the default password, but the account is blocked everywhere —
    // isSuspended() is a 403 on the API and closes the Filament panel.
    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
        'suspended_at' => now(),
    ]);

    $this->artisan('elosar:preflight')
        ->expectsOutputToContain('disabled')
        ->assertSuccessful();
});

it('stops failing once the demo password has been rotated', function () {
    healthyPreflightConfig();

    User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('a-long-random-secret-nobody-published'),
    ]);

    // A check that stays red after it has been acted on gets ignored, which
    // would defeat the point of having it.
    $this->artisan('elosar:preflight')->assertSuccessful();
});

it('reports a workspace left without an entitlement', function () {
    healthyPreflightConfig();

    // Bypasses Doctor::booted(), which is the only sanctioned path and the very
    // reason this invariant is worth checking for.
    \App\Models\Workspace::factory()->create();

    $this->artisan('elosar:preflight')
        ->expectsOutputToContain('without an entitlement')
        ->assertFailed();
});

it('always lists what it cannot verify by itself', function () {
    healthyPreflightConfig();

    // A green run must never be mistaken for "everything is verified".
    $this->artisan('elosar:preflight')
        ->expectsOutputToContain('Not checkable from here')
        ->expectsOutputToContain('backups')
        ->assertSuccessful();
});
