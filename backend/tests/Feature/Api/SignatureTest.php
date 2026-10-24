<?php

use App\Models\Doctor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Doctor signature image upload/delete (Perfil Profesional). Runs under
| `auth:sanctum` only — no active workspace header. Acts on the authenticated
| doctor's own profile exclusively.
|
| The signature lives on the PRIVATE disk. A readable signature image is enough
| to forge a prescription, so it must never be reachable without a signature on
| the URL. That guarantee is pinned in PrivateImageAccessTest; here we only
| check that uploads and deletes land on the right disk.
*/

beforeEach(function () {
    Storage::fake('local');
});

it('uploads a signature and exposes has_signature and signature_url', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->post('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->image('firma.png'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.has_signature', true)
        ->json('data');

    expect($data['signature_url'])->not->toBeNull();

    $doctor->refresh();
    expect($doctor->signature_path)->not->toBeNull();
    Storage::disk('local')->assertExists($doctor->signature_path);
});

it('deletes the signature and clears has_signature', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->image('firma.png'),
    ], ['Accept' => 'application/json'])->assertOk();

    $path = $doctor->fresh()->signature_path;
    Storage::disk('local')->assertExists($path);

    $this->deleteJson('/api/v1/profile/signature')
        ->assertOk()
        ->assertJsonPath('data.has_signature', false)
        ->assertJsonPath('data.signature_url', null);

    expect($doctor->fresh()->signature_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('rejects a non-image signature upload', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->create('firma.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('signature');
});

it('only affects the authenticated doctor, leaving another doctor untouched', function () {
    [$doctorA, $userA] = makeDoctorWithWorkspace();
    [$doctorB, $userB] = makeDoctorWithWorkspace();

    // Doctor B already has a signature on disk.
    Sanctum::actingAs($userB);
    $this->post('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->image('firma-b.png'),
    ], ['Accept' => 'application/json'])->assertOk();
    $pathB = $doctorB->fresh()->signature_path;

    // Doctor A uploads their own — must not touch B.
    Sanctum::actingAs($userA);
    $this->post('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->image('firma-a.png'),
    ], ['Accept' => 'application/json'])->assertOk();

    $pathA = $doctorA->fresh()->signature_path;

    expect($pathA)->not->toBeNull()
        ->and($pathA)->not->toBe($pathB);

    // And deleting A's signature leaves B's intact.
    $this->deleteJson('/api/v1/profile/signature')->assertOk();

    expect($doctorB->fresh()->signature_path)->toBe($pathB);
    Storage::disk('local')->assertExists($pathB);
});
