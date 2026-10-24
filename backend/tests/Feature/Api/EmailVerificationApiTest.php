<?php

use App\Models\ClinicInvite;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * E-mail confirmation for self-registered accounts.
 *
 * The rule exists to make password recovery trustworthy: a reset link is only as
 * good as the address it goes to, so an address nobody proved is a lockout
 * waiting to happen. Invited accounts are exempt — the invitation already
 * travelled to that inbox.
 */
function verifiableDoctorPayload(array $overrides = []): array
{
    $specialty = Specialty::factory()->create();

    return array_merge([
        'name' => 'Dra. Prueba',
        'email' => 'nueva-'.uniqid().'@example.com',
        'password' => 'clave-segura-123',
        'id_number' => '1710034065', // valid Ecuadorian cedula (mod-10)
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ], $overrides);
}

it('does not hand a session to a self-registered doctor before the address answers', function () {
    Notification::fake();

    $payload = verifiableDoctorPayload();

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertCreated()
        ->assertJsonPath('requires_verification', true)
        // A token here would make the login gate decorative: they would simply
        // never log out.
        ->assertJsonMissingPath('token');

    $user = User::where('email', $payload['email'])->firstOrFail();

    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);
})->group('security');

it('refuses to log in an unverified account, and says why', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(403)
        // medico-web keys on this to offer resending rather than showing a
        // generic failure the doctor cannot act on.
        ->assertJsonPath('code', 'email_unverified');
})->group('security');

it('lets the same account in once the address is confirmed', function () {
    $user = User::factory()->unverified()->create();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(403);

    $user->markEmailAsVerified();

    // Still 403, but now for the honest reason: this account has no doctor and
    // no workspace. What matters is that the verification gate stopped applying.
    $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);

    expect($response->json('code'))->not->toBe('email_unverified');
})->group('security');

it('verifies an invited account on the spot', function () {
    Notification::fake();

    $scenario = makeClinicScenario();
    $invite = ClinicInvite::create([
        'workspace_id' => $scenario['clinic']->id,
        'email' => 'invitada-'.uniqid().'@example.com',
        'role' => 'receptionist',
        'token' => Str::random(40),
        'expires_at' => now()->addDays(7),
    ]);

    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $invite->token,
        'name' => 'Recepción Prueba',
        'password' => 'clave-segura-123',
        'accept_terms' => true,
    ])->assertCreated();

    $user = User::where('email', $invite->email)->firstOrFail();

    // The token reached that inbox; asking again buys nothing.
    expect($user->hasVerifiedEmail())->toBeTrue();
    Notification::assertNotSentTo($user, VerifyEmail::class);
})->group('security');

it('confirms the address from the signed link and returns the doctor to the app', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('api.verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]);

    $this->get($url)->assertRedirectContains('verificacion=ok');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
})->group('security');

it('treats an unsigned or tampered link as expired without confirming anything', function () {
    $user = User::factory()->unverified()->create();

    // The route with no signature at all — what a forged link looks like.
    $this->get(route('api.verification.verify', [
        'id' => $user->id,
        'hash' => sha1($user->getEmailForVerification()),
    ]))->assertRedirectContains('verificacion=expirada');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->group('security');

it('refuses a signed link whose hash points at a different address', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute('api.verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1('otra-direccion@example.com'),
    ]);

    $this->get($url)->assertRedirectContains('verificacion=invalida');

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
})->group('security');

it('answers the resend endpoint the same way for any address', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $known = $this->postJson('/api/v1/auth/email/resend', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/email/resend', ['email' => 'nadie-'.uniqid().'@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json('message'))->toBe($known->json('message'));

    Notification::assertSentTimes(VerifyEmail::class, 1);
})->group('security');

it('does not resend to an address that is already confirmed', function () {
    Notification::fake();
    $user = User::factory()->create(); // the factory verifies by default

    $this->postJson('/api/v1/auth/email/resend', ['email' => $user->email])->assertOk();

    Notification::assertNothingSent();
})->group('security');
