<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

/*
| ADR-021: a session lasts while it is used. No fixed lifetime; it lapses only
| after sanctum.idle_days without a single request.
*/

it('keeps a session in daily use open for months', function () {
    $user = User::factory()->create();
    $token = $user->createToken('web');
    $token->accessToken->forceFill(['created_at' => now()->subDays(200), 'last_used_at' => now()->subHours(3)])->save();

    // Authenticated (a fresh factory user may still owe the terms: 403, not 401).
    expect($this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->status())->not->toBe(401);
});

it('ends a session nobody used for longer than the idle window', function () {
    $user = User::factory()->create();
    $token = $user->createToken('web');
    $token->accessToken->forceFill(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(61)])->save();

    $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('still honours a short-lived impersonation token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('impersonation:1', ['*'], now()->subMinute());

    $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('prunes only the sessions idle past the window', function () {
    $user = User::factory()->create();
    $idle = $user->createToken('web')->accessToken;
    $idle->forceFill(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDays(61)])->save();
    $active = $user->createToken('web')->accessToken;
    $active->forceFill(['created_at' => now()->subDays(90), 'last_used_at' => now()->subDay()])->save();

    Artisan::call('schedule:test', ['--name' => 'prune-idle-sessions']);

    expect(PersonalAccessToken::find($idle->id))->toBeNull()
        ->and(PersonalAccessToken::find($active->id))->not->toBeNull();
});
