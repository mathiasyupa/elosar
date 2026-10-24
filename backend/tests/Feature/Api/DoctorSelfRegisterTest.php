<?php

use App\Models\Doctor;
use App\Models\MedicalService;
use App\Models\Specialty;
use App\Models\User;

// ─────────────────────────────────────────────
// Public specialty catalog
// ─────────────────────────────────────────────

it('lists specialties without authentication', function () {
    Specialty::factory()->count(3)->create();

    $this->getJson('/api/v1/specialties')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('lets a doctor create a specialty that is missing from the catalog, without auth', function () {
    $this->postJson('/api/v1/specialties', ['name' => 'Cirugía Cardiovascular'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Cirugía Cardiovascular');

    $this->assertDatabaseHas('specialties', ['name' => 'Cirugía Cardiovascular']);
});

it('creating a specialty is idempotent (case-insensitive)', function () {
    $existing = Specialty::factory()->create(['name' => 'Medicina Deportiva']);

    $response = $this->postJson('/api/v1/specialties', ['name' => 'medicina deportiva']);

    $response->assertCreated()->assertJsonPath('data.id', $existing->id);
    expect(Specialty::where('name', 'Medicina Deportiva')->count())->toBe(1);
});

// ─────────────────────────────────────────────
// Doctor self-registration
// ─────────────────────────────────────────────

it('registers a doctor and creates their personal workspace', function () {
    $specialty = Specialty::factory()->create();

    $response = $this->postJson('/api/v1/auth/register', [
        'name'          => 'Dra. Ana Torres',
        'email'         => 'ana.torres@example.com',
        'password'      => 'password123',
        'id_number'     => '1710034065', // valid Ecuadorian cedula (mod-10)
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms'  => true,
    ]);

    // The account and its workspace are created, but no session comes back: the
    // address has to answer first (see EmailVerificationApiTest).
    $response->assertCreated()
        ->assertJsonPath('requires_verification', true)
        ->assertJsonMissingPath('token');

    $user = User::where('email', 'ana.torres@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->account_type)->toBe('doctor');

    $doctor = Doctor::where('user_id', $user->id)->first();
    expect($doctor)->not->toBeNull();
    expect($doctor->workspace_id)->not->toBeNull();
    expect($doctor->workspace->type)->toBe('personal');
    expect($doctor->specialty_id)->toBe($specialty->id);

    // …and once the address is confirmed, the account works: logging in returns
    // a token that reaches the doctor's own profile.
    $user->markEmailAsVerified();

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'ana.torres@example.com',
        'password' => 'password123',
    ])->assertOk()->json('token');

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('doctor.id', $doctor->id);

    // The new personal workspace ships with a short starter fee schedule (one
    // example per category) — "Servicios" isn't empty on day one, but it's not
    // a pre-filled arancel either.
    expect(MedicalService::withoutGlobalScopes()->where('workspace_id', $doctor->workspace_id)->count())
        ->toBe(5);
});

it('registers a doctor with multiple specialties, first one primary', function () {
    $cardio = Specialty::factory()->create(['name' => 'Cardiología']);
    $internal = Specialty::factory()->create(['name' => 'Medicina Interna']);

    $response = $this->postJson('/api/v1/auth/register', [
        'name'          => 'Dr. Multi Especialidad',
        'email'         => 'multi.especialidad@example.com',
        'password'      => 'password123',
        'id_number'     => '1710034065',
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [$cardio->id, $internal->id],
        'accept_terms'  => true,
    ]);

    // The response carries no doctor any more (no session until confirmed), so
    // the primary-specialty rule is checked where it actually lives.
    $response->assertCreated()->assertJsonPath('requires_verification', true);

    $doctor = Doctor::where('email', 'multi.especialidad@example.com')->first();
    expect($doctor->specialty_id)->toBe($cardio->id);
    expect($doctor->specialties()->pluck('specialties.id')->sort()->values()->all())
        ->toBe(collect([$cardio->id, $internal->id])->sort()->values()->all());
    expect($doctor->specialties()->wherePivot('is_primary', true)->first()->id)->toBe($cardio->id);
});

it('rejects registration with a duplicate email', function () {
    [, $existing] = makeDoctorWithWorkspace();
    $specialty = Specialty::factory()->create();

    $this->postJson('/api/v1/auth/register', [
        'name'          => 'Otro Doctor',
        'email'         => $existing->email,
        'password'      => 'password123',
        'id_number'     => '1710034065',
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms'  => true,
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects registration with an invalid cedula', function () {
    $specialty = Specialty::factory()->create();

    $this->postJson('/api/v1/auth/register', [
        'name'          => 'Doctor Invalido',
        'email'         => 'invalido@example.com',
        'password'      => 'password123',
        'id_number'     => '1234567890',
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms'  => true,
    ])->assertStatus(422)->assertJsonValidationErrors('id_number');
});

it('rejects registration with a non-existent specialty', function () {
    $this->postJson('/api/v1/auth/register', [
        'name'          => 'Doctor Sin Especialidad',
        'email'         => 'sinespecialidad@example.com',
        'password'      => 'password123',
        'id_number'     => '1710034065',
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [999999],
        'accept_terms'  => true,
    ])->assertStatus(422)->assertJsonValidationErrors('specialty_ids.0');
});

it('rejects registration with no specialty selected', function () {
    $this->postJson('/api/v1/auth/register', [
        'name'          => 'Doctor Sin Especialidad',
        'email'         => 'ninguna@example.com',
        'password'      => 'password123',
        'id_number'     => '1710034065',
        'phone'         => '0991234567',
        'city'          => 'Quito',
        'specialty_ids' => [],
        'accept_terms'  => true,
    ])->assertStatus(422)->assertJsonValidationErrors('specialty_ids');
});
