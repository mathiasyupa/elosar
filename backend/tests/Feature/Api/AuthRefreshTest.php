<?php

use App\Models\User;

it('swaps the session token for a fresh one and keeps the old one alive for the other tabs', function () {
    $user = User::factory()->create();
    $old = $user->createToken('web')->plainTextToken;

    $new = $this->withToken($old)->postJson('/api/v1/auth/refresh')->assertOk()->json('token');

    expect($new)->not->toBe($old)
        ->and($user->tokens()->count())->toBe(2)
        ->and($user->tokens()->whereNotNull('expires_at')->count())->toBe(0)
        ->and($user->tokens()->latest('id')->first()->name)->toBe('web');
});

it('never renews an impersonation token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('impersonation:1', ['*'], now()->addMinutes(30))->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/auth/refresh')->assertForbidden();
});
