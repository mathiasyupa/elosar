<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('the reply is identical whether or not the address has an account', function () {
    Notification::fake();

    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'nadie-'.uniqid().'@example.com']);

    // Same flashed status, and no field error either way: the form must not
    // become an oracle for discovering which doctors use Elosar.
    $known->assertSessionHasNoErrors()->assertSessionHas('status');
    $unknown->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(session('status'))->toBe(__(Illuminate\Support\Facades\Password::RESET_LINK_SENT));

    // …and the unknown address still receives nothing.
    Notification::assertSentTimes(ResetPassword::class, 1);
})->group('security');

test('a broken mailer neither leaks nor stays silent', function () {
    // Reproduces the real incident: Railway blocks outbound SMTP on 587, so the
    // send threw and a real address answered 500 while an unknown one answered
    // 302. The wording no longer differed, but the status code did — which is
    // the same oracle wearing a different hat.
    Illuminate\Support\Facades\Exceptions::fake();

    Illuminate\Support\Facades\Password::shouldReceive('sendResetLink')
        ->once()
        ->andThrow(new Symfony\Component\Mailer\Exception\TransportException(
            'Connection could not be established with host "smtp.resend.com:587"'
        ));

    $this->post('/forgot-password', ['email' => 'quien-sea@example.com'])
        ->assertStatus(302)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __(Illuminate\Support\Facades\Password::RESET_LINK_SENT));

    // The visitor learns nothing; the operator does.
    Illuminate\Support\Facades\Exceptions::assertReported(
        Symfony\Component\Mailer\Exception\TransportException::class
    );
})->group('security');

test('throttles repeated password reset requests', function () {
    Notification::fake();

    // Six per minute, like the other credential endpoints. Without a cap this is
    // a free mailer aimed at any address an attacker names.
    foreach (range(1, 6) as $i) {
        $this->post('/forgot-password', ['email' => "quien-{$i}@example.com"])
            ->assertStatus(302);
    }

    $this->post('/forgot-password', ['email' => 'septimo@example.com'])
        ->assertStatus(429);
})->group('security');

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});
