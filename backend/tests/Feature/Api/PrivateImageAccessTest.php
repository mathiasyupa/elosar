<?php

use App\Http\Resources\DoctorResource;
use App\Support\PrivateImageUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Doctor signatures, profile photos, patient photos and letterhead art used to
 * sit on the public disk, downloadable by anyone who knew (or guessed) the URL.
 * A readable signature image alone is enough to forge a prescription or a
 * medical certificate, so this behaviour is now pinned by tests.
 *
 * Note on fakes: the tests that assert WHERE a file lands use Storage::fake,
 * but the tests that assert the signed-URL mechanism deliberately run against
 * the real `local` disk. Storage::fake() replaces temporaryUrl() with an
 * unsigned stub (see Illuminate\Support\Facades\Storage::fake), so faking here
 * would assert nothing at all.
 */
function makePrivateImage(string $directory = 'signatures'): string
{
    $path = $directory.'/test-'.uniqid().'.png';
    Storage::disk('local')->put($path, 'fake-png-bytes');

    return $path;
}

afterEach(function () {
    foreach (['signatures', 'doctor-photos', 'patients/photos', 'letterheads'] as $directory) {
        foreach (Storage::disk('local')->files($directory) as $file) {
            if (str_contains($file, 'test-')) {
                Storage::disk('local')->delete($file);
            }
        }
    }
});

it('hands out a signed url, never a bare public one', function () {
    $path = makePrivateImage();

    $url = PrivateImageUrl::for($path);

    expect($url)->toContain('signature=')
        ->and($url)->toContain('expires=');
});

it('serves the image when the signature is valid', function () {
    $path = makePrivateImage();

    $this->get(PrivateImageUrl::for($path))->assertOk();
});

it('refuses to serve the image without a signature', function () {
    $path = makePrivateImage();

    // The unsigned route is exactly what a leaked path would look like.
    $this->get(route('storage.local', ['path' => $path]))->assertForbidden();
});

it('refuses to serve the image once the signature has expired', function () {
    $path = makePrivateImage();
    $url = PrivateImageUrl::for($path);

    $this->travel(PrivateImageUrl::TTL_MINUTES + 1)->minutes();

    $this->get($url)->assertForbidden();
});

it('returns null rather than a url when there is no file', function () {
    expect(PrivateImageUrl::for(null))->toBeNull();
});

it('exposes the doctor signature and photo only as signed urls', function () {
    $doctor = actingAsDoctor();
    $doctor->update([
        'signature_path'     => makePrivateImage(),
        'profile_photo_path' => makePrivateImage('doctor-photos'),
    ]);

    $payload = DoctorResource::make($doctor->fresh())->toArray(request());

    expect($payload['signature_url'])->toContain('signature=')
        ->and($payload['photo_url'])->toContain('signature=');
});

it('stores an uploaded signature on the private disk, not the public one', function () {
    Storage::fake('local');
    Storage::fake('public');

    $doctor = actingAsDoctor();

    $this->postJson('/api/v1/profile/signature', [
        'signature' => UploadedFile::fake()->image('firma.png'),
    ])->assertOk();

    $path = $doctor->fresh()->signature_path;

    expect($path)->not->toBeNull();
    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

it('moves legacy public images to the private disk and is idempotent', function () {
    Storage::fake('local');
    Storage::fake('public');

    Storage::disk('public')->put('signatures/legacy.png', 'old-bytes');
    Storage::disk('public')->put('patients/photos/legacy.jpg', 'old-bytes');

    $this->artisan('elosar:migrate-public-images')->assertSuccessful();

    Storage::disk('local')->assertExists('signatures/legacy.png');
    Storage::disk('local')->assertExists('patients/photos/legacy.jpg');
    Storage::disk('public')->assertMissing('signatures/legacy.png');
    Storage::disk('public')->assertMissing('patients/photos/legacy.jpg');

    // Running it again must not fail or destroy what it already moved.
    $this->artisan('elosar:migrate-public-images')->assertSuccessful();
    Storage::disk('local')->assertExists('signatures/legacy.png');
});
