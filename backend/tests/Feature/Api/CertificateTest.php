<?php

use App\Models\Workspace;
use App\Services\ClinicService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| SRI electronic-signature certificate upload (Ajustes screen). Workspace layer
| (requires X-Workspace-Id + membership) AND owner-only. The certificate file is
| stored on the PRIVATE `local` disk; the password is never exposed in any
| response.
*/

function certHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

beforeEach(function () {
    Storage::fake('local');
});

it('lets the owner upload the SRI certificate without leaking the password or path', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $response = $this->post('/api/v1/settings/certificate', [
        'certificate' => fakeP12File('super-secret-passphrase'),
        'password'    => 'super-secret-passphrase',
    ], array_merge(['Accept' => 'application/json'], certHeaders($ws->id)))
        ->assertOk()
        ->assertJsonPath('data.sri_certificate_uploaded', true);

    // The stored file lands on the private disk where SriCertificateLoader reads.
    $path = $ws->fresh()->sri_certificate_path;
    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);

    // The response must NOT contain the password nor the storage path.
    $raw = $response->getContent();
    expect($raw)->not->toContain('super-secret-passphrase')
        ->and($raw)->not->toContain($path)
        ->and($raw)->not->toContain('sri_certificate_password')
        ->and($raw)->not->toContain('sri_certificate_path');
});

it('persists the certificate password encrypted but never returns it from GET /settings', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/certificate', [
        'certificate' => fakeP12File('otra-clave-123'),
        'password'    => 'otra-clave-123',
    ], array_merge(['Accept' => 'application/json'], certHeaders($ws->id)))->assertOk();

    // Password is retrievable internally (encrypted cast) ...
    expect($ws->fresh()->sri_certificate_password)->toBe('otra-clave-123');

    // ... but never surfaces over the API.
    $raw = $this->getJson('/api/v1/settings', certHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.sri_certificate_uploaded', true)
        ->getContent();

    expect($raw)->not->toContain('otra-clave-123')
        ->and($raw)->not->toContain('sri_certificate_password');
});

it('forbids a non-owner member from uploading the certificate', function () {
    // Owner doctor A creates a clinic.
    [$owner, $ownerUser] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Compartida');

    // Doctor B joins the clinic as a member (but is not the owner).
    [$member, $memberUser] = makeDoctorWithWorkspace();
    app(ClinicService::class)->attachDoctor($clinic, $member);

    Sanctum::actingAs($memberUser);

    $this->post('/api/v1/settings/certificate', [
        'certificate' => fakeP12File(),
        'password'    => 'no-deberia-pasar',
    ], array_merge(['Accept' => 'application/json'], certHeaders($clinic->id)))
        ->assertStatus(403);

    // Nothing was stored.
    expect($clinic->fresh()->sri_certificate_path)->toBeNull();
});

it('rejects a certificate upload with a missing password', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/certificate', [
        'certificate' => fakeP12File(),
    ], array_merge(['Accept' => 'application/json'], certHeaders($ws->id)))
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});
