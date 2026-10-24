<?php

use App\Models\ClinicInvite;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Two obligations that had no code behind them.
 *
 * Terms acceptance: a doctor could sign up without agreeing to anything, so
 * publishing the documents would have changed nothing — there would still be no
 * evidence any particular person was shown them.
 *
 * Session revocation: the only way to evict a session on a lost phone was to
 * reset the password, which is a strange demand to make of someone whose
 * password was never the problem.
 */
function doctorSignupPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Dra. Prueba',
        'email' => 'terminos-'.uniqid().'@example.com',
        'password' => 'clave-segura-123',
        'id_number' => '1710034065', // valid Ecuadorian cedula (mod-10)
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [Specialty::factory()->create()->id],
        'accept_terms' => true,
    ], $overrides);
}

it('refuses to create an account without accepting the terms', function () {
    Notification::fake();

    $payload = doctorSignupPayload(['accept_terms' => false]);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('accept_terms');

    expect(User::where('email', $payload['email'])->exists())->toBeFalse();
})->group('security');

it('records which version of the terms was accepted, not just that they were', function () {
    Notification::fake();
    config(['lopdp.terms_version' => '2.4']);

    $payload = doctorSignupPayload();

    $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

    $user = User::where('email', $payload['email'])->firstOrFail();

    // "They accepted the terms" is worthless evidence if nobody can say which
    // text that was.
    expect($user->terms_version)->toBe('2.4')
        ->and($user->terms_accepted_at)->not->toBeNull();
})->group('security');

it('asks the same of a clinic administrator', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register/clinic-admin', [
        'name' => 'Gerente Prueba',
        'email' => 'gerente-'.uniqid().'@example.com',
        'password' => 'clave-segura-123',
        'clinic_name' => 'Centro Prueba',
        'accept_terms' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
})->group('security');

it('asks the same of someone joining by invitation', function () {
    $scenario = makeClinicScenario();
    $invite = ClinicInvite::create([
        'workspace_id' => $scenario['clinic']->id,
        'email' => 'invitada-'.uniqid().'@example.com',
        'role' => 'receptionist',
        'token' => Str::random(40),
        'expires_at' => now()->addDays(7),
    ]);

    // An invitation proves the address, not agreement to Elosar's terms — they
    // are still becoming a user of this product.
    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $invite->token,
        'name' => 'Recepción Prueba',
        'password' => 'clave-segura-123',
        'accept_terms' => false,
    ])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
})->group('security');

it('leaves accounts that predate the requirement unmarked rather than inventing consent', function () {
    // This used to rely on the factory's default, but the factory now stamps
    // the current terms so the rest of the suite is not blocked by the terms
    // gate — which quietly turned this assertion into a test of the factory
    // instead of a test of the rule. An account from before the requirement is
    // built explicitly, and the invariant is that NOTHING fills it in later.
    $user = User::factory()->create(['terms_version' => null, 'terms_accepted_at' => null]);

    $user->update(['name' => 'Cuenta antigua editada']);

    expect($user->fresh()->terms_version)->toBeNull()
        ->and($user->fresh()->terms_accepted_at)->toBeNull();
})->group('security');

it('revokes every session, including the one that asked', function () {
    [, $user] = makeDoctorWithWorkspace();

    // Three devices: the phone that was lost, a tablet, and the one in hand.
    $user->createToken('telefono');
    $user->createToken('tablet');
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/auth/logout-all')
        ->assertOk()
        ->assertJsonPath('revoked', 2);

    // A caller who asks for everything closed means everything: leaving the
    // current token alive would quietly exempt the device that pressed it.
    expect($user->fresh()->tokens()->count())->toBe(0);
})->group('security');

it('requires authentication to close every session', function () {
    $this->postJson('/api/v1/auth/logout-all')->assertUnauthorized();
})->group('security');
