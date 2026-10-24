<?php

use App\Models\Doctor;
use App\Models\GoogleAuthHandoff;
use App\Models\Specialty;
use App\Models\User;
use App\Models\WorkspaceEntitlement;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
        'app.frontend_url' => 'https://app.elosar.test',
    ]);
});

/**
 * Stand in for the round trip to Google: whatever the callback asks Socialite
 * for, it gets this identity back.
 */
function fakeGoogleIdentity(
    string $email,
    string $googleId = 'google-oauth-id-1',
    ?string $name = 'Ana Torres',
    bool $verified = true,
): void {
    $identity = new SocialiteUser;
    $identity->id = $googleId;
    $identity->name = $name;
    $identity->email = $email;
    $identity->user = ['email_verified' => $verified];

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($identity);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

/** Follows the OAuth callback and returns the one-time code it hands the SPA. */
function googleHandoffCode(): string
{
    $location = test()->get('/auth/google/callback')->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['code'] ?? null)->not->toBeNull("callback redirected to {$location}");

    return $query['code'];
}

// ─────────────────────────────────────────────
// Signing in with an account that already exists
// ─────────────────────────────────────────────

it('signs in an existing account and binds the Google identity to it', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    fakeGoogleIdentity($user->email, googleId: 'google-abc');

    $response = $this->postJson('/api/v1/auth/google/exchange', ['code' => googleHandoffCode()]);

    $response->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('doctor.id', $doctor->id);

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
    expect($user->fresh()->google_id)->toBe('google-abc');

    // And the token actually works.
    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);
});

it('verifies the address of an account that had never confirmed it', function () {
    [, $user] = makeDoctorWithWorkspace();
    $user->forceFill(['email_verified_at' => null])->save();

    fakeGoogleIdentity($user->email);

    $this->postJson('/api/v1/auth/google/exchange', ['code' => googleHandoffCode()])->assertOk();

    // Google's confirmation is the same evidence the e-mail link would produce.
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('refuses an address Google itself has not verified', function () {
    fakeGoogleIdentity('sin.verificar@gmail.com', verified: false);

    $this->get('/auth/google/callback')
        ->assertRedirect('https://app.elosar.test/login?google=correo_no_verificado');

    expect(GoogleAuthHandoff::count())->toBe(0);
});

it('keeps a suspended account out', function () {
    [, $user] = makeDoctorWithWorkspace();
    $user->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Impago'])->save();

    fakeGoogleIdentity($user->email);

    $this->postJson('/api/v1/auth/google/exchange', ['code' => googleHandoffCode()])
        ->assertStatus(403);
});

it('keeps out an account with neither a doctor nor a clinic membership', function () {
    // A bare User is exactly what a careless OAuth flow creates, and what
    // routes/auth.php warns about: no workspace, no trial, no entitlement.
    $user = User::factory()->create(['account_type' => 'doctor']);
    fakeGoogleIdentity($user->email);

    $this->postJson('/api/v1/auth/google/exchange', ['code' => googleHandoffCode()])
        ->assertStatus(403);
});

// ─────────────────────────────────────────────
// Signing up
// ─────────────────────────────────────────────

it('reports that a brand-new Google identity still has to sign up', function () {
    fakeGoogleIdentity('nueva.doctora@gmail.com', name: 'Nueva Doctora');

    $code = googleHandoffCode();

    $this->postJson('/api/v1/auth/google/exchange', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('requires_registration', true)
        ->assertJsonPath('email', 'nueva.doctora@gmail.com')
        ->assertJsonPath('name', 'Nueva Doctora')
        ->assertJsonMissingPath('token');

    // Deliberately NOT consumed: the wizard presents the same code on submit.
    expect(GoogleAuthHandoff::where('code', $code)->first()->used_at)->toBeNull();
});

it('signs up a doctor through Google and lets them straight in', function () {
    $specialty = Specialty::factory()->create();
    fakeGoogleIdentity('nueva.doctora@gmail.com', googleId: 'google-xyz', name: 'Nueva Doctora');

    $code = googleHandoffCode();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Dra. Nueva Doctora',
        'email' => 'nueva.doctora@gmail.com',
        'google_code' => $code,
        'id_number' => validEcuadorianCedula(),
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ]);

    // The whole point: no "confirma tu correo" step, because Google already did.
    $response->assertCreated()->assertJsonMissingPath('requires_verification');
    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $user = User::where('email', 'nueva.doctora@gmail.com')->firstOrFail();
    expect($user->google_id)->toBe('google-xyz');
    expect($user->password)->toBeNull();
    expect($user->hasVerifiedEmail())->toBeTrue();

    // The tenant exists and is commercially alive — a workspace without an
    // entitlement is read-only, which would be a silently broken signup.
    $doctor = Doctor::where('user_id', $user->id)->firstOrFail();
    expect($doctor->workspace_id)->not->toBeNull();
    $entitlement = WorkspaceEntitlement::where('workspace_id', $doctor->workspace_id)->first();
    expect($entitlement)->not->toBeNull();
    expect($entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL);

    expect(GoogleAuthHandoff::where('code', $code)->first()->used_at)->not->toBeNull();

    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('doctor.id', $doctor->id);
});

it('signs up a clinic administrator through Google', function () {
    fakeGoogleIdentity('admin.clinica@gmail.com', googleId: 'google-clinic');

    $response = $this->postJson('/api/v1/auth/register/clinic-admin', [
        'name' => 'Admin Clínica',
        'email' => 'admin.clinica@gmail.com',
        'google_code' => googleHandoffCode(),
        'clinic_name' => 'Clínica Nueva',
        'accept_terms' => true,
    ]);

    $response->assertCreated()->assertJsonMissingPath('requires_verification');
    expect($response->json('token'))->toBeString()->not->toBeEmpty();

    $user = User::where('email', 'admin.clinica@gmail.com')->firstOrFail();
    expect($user->account_type)->toBe('staff');
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->password)->toBeNull();
});

it('refuses to register an address other than the one Google authorised', function () {
    Specialty::factory()->create();
    fakeGoogleIdentity('legitima@gmail.com');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Impostor',
        // The handoff vouches for legitima@gmail.com, not for this.
        'email' => 'victima@gmail.com',
        'google_code' => googleHandoffCode(),
        'id_number' => validEcuadorianCedula(),
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [Specialty::first()->id],
        'accept_terms' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    expect(User::where('email', 'victima@gmail.com')->exists())->toBeFalse();
});

it('refuses a code that was already spent', function () {
    $specialty = Specialty::factory()->create();
    fakeGoogleIdentity('reutiliza@gmail.com');

    $code = googleHandoffCode();

    $payload = [
        'name' => 'Dra. Primera',
        'email' => 'reutiliza@gmail.com',
        'google_code' => $code,
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ];

    $this->postJson('/api/v1/auth/register', $payload + ['id_number' => validEcuadorianCedula()])
        ->assertCreated();

    // Same code, a second account.
    $this->postJson('/api/v1/auth/register', array_merge($payload, [
        'email' => 'reutiliza.otra@gmail.com',
        'id_number' => validEcuadorianCedula(),
    ]))->assertStatus(422)->assertJsonValidationErrors('google_code');
});

it('refuses a code that has expired', function () {
    $handoff = GoogleAuthHandoff::create([
        'code' => 'caducado-'.str_repeat('x', 20),
        'google_id' => 'google-old',
        'email' => 'tarde@gmail.com',
        'name' => 'Tarde',
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson('/api/v1/auth/google/exchange', ['code' => $handoff->code])
        ->assertStatus(404);
});

it('refuses a code that does not exist', function () {
    $this->postJson('/api/v1/auth/google/exchange', ['code' => 'inventado'])
        ->assertStatus(404);
});

// ─────────────────────────────────────────────
// Living alongside password login
// ─────────────────────────────────────────────

it('fails cleanly when someone tries a password on a Google-only account', function () {
    $specialty = Specialty::factory()->create();
    fakeGoogleIdentity('solo.google@gmail.com');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dra. Solo Google',
        'email' => 'solo.google@gmail.com',
        'google_code' => googleHandoffCode(),
        'id_number' => validEcuadorianCedula(),
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ])->assertCreated();

    // The stored password is null. This must be the ordinary "wrong credentials"
    // answer — not a 500, and not a hint that the account exists.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'solo.google@gmail.com',
        'password' => 'loquesea123',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('lets a Google-only account recover through the ordinary password reset', function () {
    $specialty = Specialty::factory()->create();
    fakeGoogleIdentity('recupera@gmail.com');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dra. Recupera',
        'email' => 'recupera@gmail.com',
        'google_code' => googleHandoffCode(),
        'id_number' => validEcuadorianCedula(),
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ])->assertCreated();

    // Otherwise a doctor who forgets they used Google has no way back in.
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'recupera@gmail.com'])
        ->assertOk();
});

it('still requires a password when no Google code is presented', function () {
    $specialty = Specialty::factory()->create();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dra. Sin Nada',
        'email' => 'sin.nada@gmail.com',
        'id_number' => validEcuadorianCedula(),
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ])->assertStatus(422)->assertJsonValidationErrors('password');
});
