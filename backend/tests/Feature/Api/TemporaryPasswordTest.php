<?php

use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('tells a temporary-password user to change it at login and blocks tenant routes', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $user->update(['must_change_password' => true]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk()->assertJsonPath('legal.password_change_required', true);

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/patients', ['X-Workspace-Id' => (string) $workspace->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'temporary_password_change_required');
});

it('requires the current password and a confirmed different new password', function () {
    [, $user] = makeDoctorWithWorkspace();
    $user->update(['must_change_password' => true]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/change-temporary-password', [
        'current_password' => 'incorrect',
        'password' => 'new-private-password',
        'password_confirmation' => 'new-private-password',
    ])->assertUnprocessable();

    $this->postJson('/api/v1/auth/change-temporary-password', [
        'current_password' => 'password',
        'password' => 'new-private-password',
        'password_confirmation' => 'mismatch',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/auth/change-temporary-password', [
        'current_password' => 'password',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertUnprocessable();

    expect($user->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('changes the temporary password, revokes every token, and restores tenant access after login', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $user->update(['must_change_password' => true]);
    $firstToken = $user->createToken('first-device')->plainTextToken;
    $user->createToken('second-device');

    $this->withToken($firstToken)->postJson('/api/v1/auth/change-temporary-password', [
        'current_password' => 'password',
        'password' => 'new-private-password',
        'password_confirmation' => 'new-private-password',
    ])->assertOk();

    expect($user->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('new-private-password', $user->fresh()->password))->toBeTrue()
        ->and($user->fresh()->tokens()->count())->toBe(0);

    // RequestGuard caches the authenticated user within a feature test.
    // Reset it to simulate the next independent HTTP request.
    app('auth')->forgetGuards();
    $this->withToken($firstToken)->getJson('/api/v1/auth/me')->assertUnauthorized();

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'new-private-password',
    ])->assertOk()->assertJsonPath('legal.password_change_required', false);

    $this->withToken($response->json('token'))
        ->getJson('/api/v1/patients', ['X-Workspace-Id' => (string) $workspace->id])
        ->assertOk();
});
