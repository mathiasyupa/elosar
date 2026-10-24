<?php

use App\Models\Cie10Code;
use App\Models\Doctor;
use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

function settingsHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Settings (owner vs non-owner)
// ─────────────────────────────────────────────

it('lets the workspace owner update fiscal data', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings', [
        'razon_social' => 'Consultorio Dr. House S.A.',
        'ruc' => '1790012345001',
    ], settingsHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.razon_social', 'Consultorio Dr. House S.A.');

    expect($ws->fresh()->ruc)->toBe('1790012345001');
});

it('stores the SRI environment using the values the engine reads', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings', [
        'sri_environment' => 'production',
    ], settingsHeaders($ws->id))->assertOk();

    expect($ws->fresh()->sri_environment)->toBe(Workspace::SRI_ENVIRONMENT_PRODUCTION)
        ->and($ws->fresh()->sriAmbienteCode())->toBe('2');
});

it('explains a bad fiscal field in Spanish, under the field that caused it', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // A cédula typed without the trailing 001 — the mistake that actually
    // happened. The answer has to name the field AND say what is missing.
    $this->putJson('/api/v1/settings', ['ruc' => '1712860848'], ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ruc')
        ->assertJsonPath('errors.ruc.0', 'El RUC tiene 13 dígitos: tu cédula seguida de 001.');

    $this->putJson('/api/v1/settings', ['cod_establecimiento' => '4'], ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(422)
        ->assertJsonPath('errors.cod_establecimiento.0', 'El código de establecimiento tiene 3 dígitos (001 si solo tienes uno).');
});

it('rejects the SRI ambiente digit as an environment', function () {
    // Regression: accepting "2" here persisted a value the engine does not
    // recognize, silently routing production vouchers to the test endpoint.
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings', [
        'sri_environment' => '2',
    ], settingsHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sri_environment');
});

// ─────────────────────────────────────────────
// SRI signing certificate upload
// ─────────────────────────────────────────────

// makeTestPkcs12() / fakeP12File() live in tests/Pest.php — shared with CertificateTest.

it('accepts a .p12 certificate that fileinfo reports as octet-stream', function () {
    // Regression: `mimes:p12,pfx` sniffs content, and PKCS#12 bundles are
    // detected as application/octet-stream — so every valid BCE certificate was
    // rejected with "the file must be of type p12".
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    [$bundle, $password] = makeTestPkcs12();
    $file = UploadedFile::fake()->createWithContent('firma.p12', $bundle);

    expect(mime_content_type($file->getRealPath()))->toBe('application/octet-stream');

    $this->post('/api/v1/settings/certificate', [
        'certificate' => $file,
        'password' => $password,
    ], settingsHeaders($ws->id) + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.sri_certificate_uploaded', true);

    expect($ws->fresh()->sri_certificate_path)->not->toBeNull()
        ->and($ws->fresh()->sri_certificate_password)->toBe($password);
});

it('rejects a certificate whose password is wrong, at upload time', function () {
    // Without this check a wrong password was stored happily and only failed
    // much later inside AuthorizeSriVoucherJob, marking vouchers rejected with
    // no clue for the emitter.
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    [$bundle] = makeTestPkcs12();

    $this->post('/api/v1/settings/certificate', [
        'certificate' => UploadedFile::fake()->createWithContent('firma.p12', $bundle),
        'password' => 'clave-equivocada',
    ], settingsHeaders($ws->id) + ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('certificate');

    expect($ws->fresh()->sri_certificate_path)->toBeNull();
});

it('reads an RC2-encrypted .p12, like the ones the Ecuadorian CAs issue', function () {
    // Canary for the OpenSSL 3 legacy provider.
    //
    // The .p12 bundles issued by the BCE / Security Data are encrypted with
    // RC2-40. OpenSSL 3 moved that cipher into the `legacy` provider and does
    // not load it by default, so openssl_pkcs12_read() fails with
    // "error:0308010C:digital envelope routines::unsupported" — every real
    // certificate is rejected while self-signed test ones work fine.
    //
    // The provider is activated in docker/openssl.cnf (mounted by compose.yaml)
    // and in the production Dockerfile. If this test starts failing, that
    // configuration was lost in a rebuild and SRI signing is dead in the water.
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $bundle = base64_decode(trim(file_get_contents(base_path('tests/Fixtures/sri/legacy-rc2.p12.b64'))));

    $this->post('/api/v1/settings/certificate', [
        'certificate' => UploadedFile::fake()->createWithContent('firma-bce.p12', $bundle),
        'password' => 'elosar',
    ], settingsHeaders($ws->id) + ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.sri_certificate_uploaded', true);
});

it('rejects a file that is not a PKCS#12 bundle', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/certificate', [
        'certificate' => UploadedFile::fake()->createWithContent('firma.p12', 'esto no es un certificado'),
        'password' => 'loquesea',
    ], settingsHeaders($ws->id) + ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('certificate');
});

it('forbids a non-owner member from updating workspace settings', function () {
    // Owner doctor + their clinic.
    [$owner] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create(['owner_doctor_id' => $owner->id]);

    // A second doctor who is a member of the clinic but not its owner.
    [$member, $memberUser] = makeDoctorWithWorkspace();
    $member->clinics()->attach($clinic->id); // grants access via clinic_doctor pivot

    Sanctum::actingAs($memberUser);

    $this->putJson('/api/v1/settings', [
        'razon_social' => 'Intento no autorizado',
    ], settingsHeaders($clinic->id))
        ->assertStatus(403);

    expect($clinic->fresh()->razon_social)->not->toBe('Intento no autorizado');
});

it('exposes settings to a member (read) but not write', function () {
    [$owner] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create(['owner_doctor_id' => $owner->id, 'name' => 'Clínica Central']);

    [$member, $memberUser] = makeDoctorWithWorkspace();
    $member->clinics()->attach($clinic->id);

    Sanctum::actingAs($memberUser);

    $this->getJson('/api/v1/settings', settingsHeaders($clinic->id))
        ->assertOk()
        ->assertJsonPath('data.name', 'Clínica Central');
});

// ─────────────────────────────────────────────
// Profile
// ─────────────────────────────────────────────

it('lets a doctor read and update their own profile', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // Profile runs under auth:sanctum only — no workspace header needed.
    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.id', $doctor->id);

    $this->putJson('/api/v1/profile', [
        'phone' => '0999999999',
        'city' => 'Cuenca',
    ])
        ->assertOk()
        ->assertJsonPath('data.phone', '0999999999');

    expect($doctor->fresh()->city)->toBe('Cuenca');
});

it('persists the ACESS and SENESCYT professional registries on the profile', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/profile', [
        'medical_license' => 'MSP-12345',
        'acess_code' => 'ACESS-67890',
        'senescyt_registration' => '1006-2024-1234567',
    ])
        ->assertOk()
        ->assertJsonPath('data.acess_code', 'ACESS-67890')
        ->assertJsonPath('data.senescyt_registration', '1006-2024-1234567');

    $fresh = $doctor->fresh();
    expect($fresh->acess_code)->toBe('ACESS-67890')
        ->and($fresh->senescyt_registration)->toBe('1006-2024-1234567');
});

it('lets a doctor edit their specialties after registration', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $cardiology = Specialty::factory()->create(['name' => 'Cardiología']);
    $internal = Specialty::factory()->create(['name' => 'Medicina Interna']);

    // First id becomes the primary specialty (printed on prescriptions).
    $this->putJson('/api/v1/profile', [
        'specialty_ids' => [$cardiology->id, $internal->id],
    ])
        ->assertOk()
        ->assertJsonPath('data.specialty', 'Cardiología')
        ->assertJsonCount(2, 'data.specialties');

    $fresh = $doctor->fresh();
    // Legacy singular column tracks the primary specialty.
    expect($fresh->specialty_id)->toBe($cardiology->id);
    expect($fresh->specialties()->wherePivot('is_primary', true)->pluck('specialties.id')->all())
        ->toBe([$cardiology->id]);
    expect($fresh->specialties()->pluck('specialties.id')->sort()->values()->all())
        ->toBe(collect([$cardiology->id, $internal->id])->sort()->values()->all());
});

it('rejects a profile update with an unknown specialty id', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/profile', [
        'specialty_ids' => [999999],
    ])->assertStatus(422)->assertJsonValidationErrors('specialty_ids.0');
});

// ─────────────────────────────────────────────
// Catalogs
// ─────────────────────────────────────────────

it('returns only the active workspace medical services', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    MedicalService::factory()->create(['workspace_id' => $ws->id, 'name' => 'Consulta propia']);

    $otherWs = Workspace::factory()->create();
    MedicalService::factory()->create(['workspace_id' => $otherWs->id, 'name' => 'Consulta ajena']);

    // Inactive services are filtered out.
    MedicalService::factory()->inactive()->create(['workspace_id' => $ws->id, 'name' => 'Servicio inactivo']);

    Sanctum::actingAs($user);

    $names = collect(
        $this->getJson('/api/v1/medical-services', settingsHeaders($ws->id))->assertOk()->json('data')
    )->pluck('name');

    expect($names)->toContain('Consulta propia')
        ->and($names)->not->toContain('Consulta ajena')
        ->and($names)->not->toContain('Servicio inactivo');
});

it('searches the global CIE-10 catalog by code or description', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    Cie10Code::factory()->create(['code' => 'J06.9', 'description' => 'Infección aguda de las vías respiratorias']);
    Cie10Code::factory()->create(['code' => 'K29.7', 'description' => 'Gastritis no especificada']);

    Sanctum::actingAs($user);

    // Match by code.
    $byCode = $this->getJson('/api/v1/cie10?q=J06', settingsHeaders($ws->id))->assertOk()->json('data');
    expect(collect($byCode)->pluck('code'))->toContain('J06.9');

    // Match by description.
    $byDesc = $this->getJson('/api/v1/cie10?q=Gastritis', settingsHeaders($ws->id))->assertOk()->json('data');
    expect(collect($byDesc)->pluck('code'))->toContain('K29.7');

    // A query shorter than 2 chars returns nothing.
    $short = $this->getJson('/api/v1/cie10?q=J', settingsHeaders($ws->id))->assertOk()->json('data');
    expect($short)->toBeEmpty();
});

// ─────────────────────────────────────────────
// Attachment download isolation
// ─────────────────────────────────────────────

it('returns 404 when downloading an attachment from another workspace', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);
    $foreignRecord = MedicalRecord::factory()->create([
        'workspace_id' => $foreignWs->id,
        'patient_id' => $foreignPatient->id,
    ]);
    $foreignAttachment = MedicalAttachment::create([
        'medical_record_id' => $foreignRecord->id,
        'title' => 'lab.pdf',
        'file_path' => 'attachments/x/lab.pdf',
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/v1/attachments/{$foreignAttachment->id}/download", settingsHeaders($ws->id))
        ->assertNotFound();
});

it('saves how the doctor signs, and rejects anything but Dr. or Dra.', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/profile')->assertOk()->json('data.honorific'))->toBeNull();

    $this->putJson('/api/v1/profile', ['honorific' => 'Dra.'])->assertOk()->assertJsonPath('data.honorific', 'Dra.');
    $this->putJson('/api/v1/profile', ['honorific' => 'Ing.'])->assertUnprocessable()->assertJsonValidationErrors('honorific');
    $this->putJson('/api/v1/profile', ['honorific' => null])->assertOk()->assertJsonPath('data.honorific', null);
});

it('lets a doctor choose a physical stamp signature on their own profile', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/profile', ['uses_physical_stamp_signature' => true])
        ->assertOk()
        ->assertJsonPath('data.uses_physical_stamp_signature', true);

    expect($doctor->fresh()->uses_physical_stamp_signature)->toBeTrue();
});

it('saves a profile with blank phone and city instead of failing', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/profile', [
        'name' => 'Jeferson Andres Imaicela Pilco', 'phone' => '', 'city' => '', 'honorific' => 'Dr.',
    ])->assertOk()->assertJsonPath('data.name', 'Jeferson Andres Imaicela Pilco');

    expect($doctor->fresh()->phone)->toBe('')
        ->and($doctor->fresh()->honorific)->toBe('Dr.');
});
