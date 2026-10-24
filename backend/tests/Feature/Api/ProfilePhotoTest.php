<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Doctor profile photo upload/delete (Perfil Profesional). Mirrors the signature:
| `auth:sanctum` only, acts solely on the authenticated doctor's own profile.
*/

beforeEach(function () {
    Storage::fake('local');
});

it('uploads a profile photo and exposes has_photo and photo_url', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->post('/api/v1/profile/photo', [
        'photo' => UploadedFile::fake()->image('foto.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.has_photo', true)
        ->json('data');

    expect($data['photo_url'])->not->toBeNull();

    $doctor->refresh();
    expect($doctor->profile_photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($doctor->profile_photo_path);
});

it('deletes the profile photo and clears has_photo', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/profile/photo', [
        'photo' => UploadedFile::fake()->image('foto.jpg'),
    ], ['Accept' => 'application/json'])->assertOk();

    $path = $doctor->fresh()->profile_photo_path;
    Storage::disk('local')->assertExists($path);

    $this->deleteJson('/api/v1/profile/photo')
        ->assertOk()
        ->assertJsonPath('data.has_photo', false)
        ->assertJsonPath('data.photo_url', null);

    expect($doctor->fresh()->profile_photo_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('rejects a non-image photo upload', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/profile/photo', [
        'photo' => UploadedFile::fake()->create('foto.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('photo');
});
