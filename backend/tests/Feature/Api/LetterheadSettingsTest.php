<?php

use App\Models\Doctor;
use App\Services\ClinicService;
use App\Support\LetterheadData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function letterheadHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

beforeEach(function () {
    Storage::fake('local');
});

it('lets the owner upload a workspace logo and update letterhead texts', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/logo', [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ], array_merge(['Accept' => 'application/json'], letterheadHeaders($ws->id)))
        ->assertOk()
        ->assertJsonPath('data.logo_url', fn ($url) => is_string($url) && $url !== '');

    expect($ws->fresh()->logo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($ws->fresh()->logo_path);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => [
            'header' => 'Dr. House — Diagnóstico',
            'subheader' => 'Medicina Interna',
            'address' => 'Quito, Ecuador',
            'phone' => '+593 2 222 3333',
            'issue_city' => 'Rumiñahua',
            'footer' => 'Horario: Lun–Vie 8h–18h',
        ],
    ], letterheadHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.prescription_settings.header', 'Dr. House — Diagnóstico')
        ->assertJsonPath('data.prescription_settings.issue_city', 'Rumiñahua');

    $settings = $ws->fresh()->prescription_settings;
    expect($settings['footer'])->toBe('Horario: Lun–Vie 8h–18h')
        ->and($settings['issue_city'])->toBe('Rumiñahua');
});

it('lets the owner upload a letterhead banner and switch to image header mode', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/header-image', [
        'header_image' => UploadedFile::fake()->image('membrete.png', 900, 120),
    ], array_merge(['Accept' => 'application/json'], letterheadHeaders($ws->id)))
        ->assertOk()
        ->assertJsonPath('data.header_image_url', fn ($url) => is_string($url) && $url !== '');

    expect($ws->fresh()->header_image_path)->not->toBeNull();
    Storage::disk('local')->assertExists($ws->fresh()->header_image_path);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header_mode' => 'image'],
    ], letterheadHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.prescription_settings.header_mode', 'image');

    // Deleting the banner clears the path.
    $path = $ws->fresh()->header_image_path;
    $this->deleteJson('/api/v1/settings/header-image', [], letterheadHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.header_image_url', null);
    Storage::disk('local')->assertMissing($path);
});

it('rejects an invalid header mode', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header_mode' => 'hologram'],
    ], letterheadHeaders($ws->id))
        ->assertStatus(422);
});

it('lets the owner delete the workspace logo', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/logo', [
        'logo' => UploadedFile::fake()->image('logo.png'),
    ], array_merge(['Accept' => 'application/json'], letterheadHeaders($ws->id)))->assertOk();

    $path = $ws->fresh()->logo_path;

    $this->deleteJson('/api/v1/settings/logo', [], letterheadHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    expect($ws->fresh()->logo_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('forbids a non-owner from updating letterhead settings', function () {
    [$owner, $ownerUser] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Letra');

    [$member, $memberUser] = makeDoctorWithWorkspace();
    app(ClinicService::class)->attachDoctor($clinic, $member);

    Sanctum::actingAs($memberUser);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header' => 'Hack'],
    ], letterheadHeaders($clinic->id))
        ->assertStatus(403);
});

it('prints the uploaded banner at the height the clinic chose', function () {
    $banner = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    $style = LetterheadData::headerImageStyle(['header_image_height' => 45]);

    $html = view('partials.pdf-letterhead', [
        'headerMode' => 'image', 'headerImage' => $banner, 'headerImageStyle' => $style,
    ])->render();

    expect($html)->toContain('max-height: 45mm')
        ->and(LetterheadData::headerImageStyle([]))->toBeNull()
        ->and(LetterheadData::headerImageStyle(['header_image_height' => 999]))->toBe('max-height: 80mm');
});

it('shrinks a huge transparent logo and keeps it transparent', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $image = imagecreatetruecolor(3000, 1500);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledrectangle($image, 500, 300, 2500, 1200, imagecolorallocate($image, 20, 80, 160));
    $file = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($image, $file);

    $this->postJson('/api/v1/settings/logo', [
        'logo' => new UploadedFile($file, 'logo.png', 'image/png', null, true),
    ], ['X-Workspace-Id' => (string) $ws->id])->assertOk();

    $path = $ws->fresh()->logo_path;
    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($path)->toEndWith('.png')
        ->and(max($width, $height))->toBe(800)
        ->and($width / $height)->toEqual(2);
});

it('stores an opaque banner photo as a JPEG', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/settings/header-image', [
        'header_image' => UploadedFile::fake()->image('banner.jpg', 3600, 900),
    ], ['X-Workspace-Id' => (string) $ws->id])->assertOk();

    $path = $ws->fresh()->header_image_path;
    [$width] = getimagesizefromstring(Storage::disk('local')->get($path));

    expect($path)->toEndWith('.jpg')->and($width)->toBe(2400);
});

it('signs the receta as «Dr.» with the title of the visit and the cédula only', function () {
    $doctor = Doctor::factory()->make([
        'name' => 'Pedro Vera', 'id_number' => '0102030405', 'phone' => '0991112233', 'email' => 'luis@clinica.ec',
        'medical_license' => 'MSP-123', 'senescyt_registration' => '1005-2015-1',
    ]);

    $html = view('prescriptions.parts.signature', ['doctor' => $doctor, 'signature' => null, 'specialty' => 'Médico Familiar'])->render();

    expect($html)->toContain('Dr. Pedro Vera')
        ->and($html)->toContain('Médico Familiar')
        ->and($html)->toContain('C.I.: 0102030405')
        ->and($html)->not->toContain('SENESCYT')
        ->and($html)->not->toContain('MSP-123')
        ->and($html)->not->toContain('0991112233')
        ->and($html)->not->toContain('luis@clinica.ec');
});

it('prints only the cédula of a clinic doctor, never their phone or email', function () {
    $s = makeClinicScenario();
    $doctor = Doctor::factory()->make(['id_number' => '0102030405', 'phone' => '0991112233', 'email' => 'luis@clinica.ec']);
    $data = LetterheadData::for($s['clinic'], $doctor);

    $html = view('prescriptions.parts.signature', $data + ['specialty' => null])->render();

    expect($data['showDoctorContact'])->toBeFalse()
        ->and($html)->toContain('C.I.: 0102030405')
        ->and($html)->not->toContain('0991112233')
        ->and($html)->not->toContain('luis@clinica.ec');
});

it('prints the clinic phone and email under a clinic doctor signature, never the doctor own', function () {
    $s = makeClinicScenario();
    $s['clinic']->update(['prescription_settings' => ['phone' => '(02) 222-3333', 'email' => 'info@clinica-ejemplo.ec']]);
    $doctor = Doctor::factory()->make(['id_number' => '0102030405', 'phone' => '0991112233', 'email' => 'luis@clinica.ec']);
    $data = LetterheadData::for($s['clinic']->fresh(), $doctor);

    $receta = view('prescriptions.parts.signature', $data + ['specialty' => null])->render();
    $order = view('partials.pdf-signature', $data)->render();

    foreach ([$receta, $order] as $html) {
        expect($html)->toContain('(02) 222-3333')
            ->and($html)->toContain('info@clinica-ejemplo.ec')
            ->and($html)->not->toContain('0991112233')
            ->and($html)->not->toContain('luis@clinica.ec');
    }
});

it('keeps the doctor own contact under the signature in a personal practice', function () {
    [$doctor, , $ws] = makeDoctorWithWorkspace();
    $doctor->forceFill(['phone' => '0991112233', 'email' => 'luis@consultorio.ec']);

    expect(LetterheadData::signatureContact($ws, $doctor, []))
        ->toBe(['phone' => '0991112233', 'email' => 'luis@consultorio.ec']);
});
