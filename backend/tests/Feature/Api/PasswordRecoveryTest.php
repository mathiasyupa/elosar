<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

/**
 * Password recovery for medico-web (JSON), as opposed to the Blade flow Breeze
 * left on the API domain.
 *
 * The point of most of these is that the answer never reveals whether an address
 * belongs to an account — including when the mailer is broken, which is how the
 * leak came back the day Railway blocked outbound SMTP on port 587.
 */
it('accepts a recovery request for a real account and sends the link', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, ResetPassword::class);
})->group('security');

it('answers an unknown address exactly like a real one', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nadie-'.uniqid().'@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json('message'))->toBe($known->json('message'));

    // Identical reply, and still nothing sent to the address that has no account.
    Notification::assertSentTimes(ResetPassword::class, 1);
})->group('security');

it('keeps the reply generic when the mailer is down', function () {
    Exceptions::fake();

    Password::shouldReceive('sendResetLink')
        ->once()
        ->andThrow(new Symfony\Component\Mailer\Exception\TransportException('SMTP unreachable'));

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'quien-sea@example.com'])
        ->assertOk();

    // Silent to the caller, loud to the operator.
    Exceptions::assertReported(Symfony\Component\Mailer\Exception\TransportException::class);
})->group('security');

it('points the reset link at medico-web, never at the API domain', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        expect($url)->toStartWith(rtrim((string) config('app.frontend_url'), '/'))
            ->and($url)->toContain('/restablecer-contrasena?token=');

        return true;
    });
})->group('security');

it('resets the password with a valid token and revokes existing sessions', function () {
    Notification::fake();
    $user = User::factory()->create();

    // A session the intruder might be holding.
    Sanctum::actingAs($user);
    $user->createToken('web');
    expect($user->fresh()->tokens()->count())->toBeGreaterThan(0);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertOk();

        return true;
    });

    expect(Hash::check('clave-nueva-123', $user->fresh()->password))->toBeTrue()
        // Changing the password has to end an intruder's access, not just stop
        // them signing in again.
        ->and($user->fresh()->tokens()->count())->toBe(0);
})->group('security');

it('rejects an invalid or expired reset token', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'token-inventado',
        'email' => $user->email,
        'password' => 'clave-nueva-123',
        'password_confirmation' => 'clave-nueva-123',
    ])->assertStatus(422);

    expect(Hash::check('clave-nueva-123', $user->fresh()->password))->toBeFalse();
})->group('security');

it('requires the confirmation to match and a minimum length', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'lo-que-sea',
        'email' => $user->email,
        'password' => 'corta',
        'password_confirmation' => 'otra-distinta',
    ])->assertStatus(422)->assertJsonValidationErrors('password');
})->group('security');
