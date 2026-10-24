<?php

use App\Models\Doctor;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClinicService;
use App\Support\ActiveWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Authenticates as a doctor and sets their personal workspace as active.
 * Returns the Doctor so assertions can be chained in the test.
 */
function actingAsDoctor(?Doctor $doctor = null): Doctor
{
    if (! $doctor) {
        [$doctor] = makeDoctorWithWorkspace();
    }

    test()->actingAs($doctor->user);

    // Force correct resolution of the active workspace in the test session
    $workspaceId = $doctor->workspace_id;
    if ($workspaceId) {
        session([ActiveWorkspace::SESSION_KEY => $workspaceId]);
        app(ActiveWorkspace::class); // force singleton resolution
    }

    return $doctor;
}

/**
 * Minimal valid data to create a Patient directly (without factory).
 * Avoids NOT NULL violations in workspace isolation tests.
 */
function minimalPatientData(array $overrides = []): array
{
    return array_merge([
        'name' => 'Paciente Test',
        'id_number' => validEcuadorianCedula(),
        'document_type' => 'cedula',
        'document_country' => 'EC',
        'birth_date' => '1990-01-01',
        'gender' => 'male',
        'city' => 'Quito',
        'address' => 'Av. Amazonas N34-120 y Av. Atahualpa',
        'email' => fake()->unique()->safeEmail(),
        'phone' => '0991234567',
    ], $overrides);
}

/**
 * Generate a valid Ecuadorian cedula (mod-10) for tests.
 */
function validEcuadorianCedula(): string
{
    $province = str_pad((string) fake()->numberBetween(1, 24), 2, '0', STR_PAD_LEFT);
    // 0-5, not 0-6: a third digit of 6 marks a public entity, not a natural
    // person, and EcuadorianId rejects it (see EcuadorianId::cedula). Generating
    // it made roughly one in seven cédulas invalid, so any test calling this
    // helper failed at random — which is exactly how it looked.
    $third = fake()->numberBetween(0, 5);
    $digits = $province.$third.fake()->numerify('######');

    $coeficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
    $sum = 0;

    for ($i = 0; $i < 9; $i++) {
        $value = (int) $digits[$i] * $coeficients[$i];
        $sum += $value >= 10 ? $value - 9 : $value;
    }

    $checksum = (10 - ($sum % 10)) % 10;

    return $digits.$checksum;
}

/**
 * Creates a doctor with a user and returns [Doctor, User, Workspace].
 * Useful when the test needs the three objects separately.
 */
function makeDoctorWithWorkspace(): array
{
    return DB::transaction(function (): array {
        $user = User::factory()->create(['account_type' => 'doctor']);

        // Doctor::booted() creates the personal workspace and, through
        // WorkspaceEntitlementService, its account grant and trial
        // entitlement. Creating either by hand here would collide with it.
        $doctor = Doctor::factory()->withUser($user)->create();
        $doctor->refresh();

        return [$doctor, $user, $doctor->workspace()->firstOrFail()];
    });
}

/**
 * Builds a real PKCS#12 bundle in memory for the SRI certificate tests.
 *
 * The upload endpoint verifies the file actually opens as PKCS#12 with the given
 * password, so a zero-filled `UploadedFile::fake()->create()` is rejected — as it
 * should be. Tests that only care about the surrounding behaviour (owner-only,
 * secrets never leaked) still need bytes a real certificate would have.
 *
 * @return array{0: string, 1: string} [pkcs12 contents, password]
 */
function makeTestPkcs12(string $password = 'secreto123', int $days = 825): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Prueba SRI'], $key);
    // 825 days mirrors a real Ecuadorian signing certificate. It used to be 1,
    // which SriDiagnostics correctly flagged as "expires in under 30 days" —
    // the check working, but every diagnostics test failing on step two.
    $cert = openssl_csr_sign($csr, null, $key, $days);

    $bundle = '';
    openssl_pkcs12_export($cert, $bundle, $key, $password);

    return [$bundle, $password];
}

/** An UploadedFile carrying a real PKCS#12 bundle, for the certificate endpoint. */
function fakeP12File(string $password = 'secreto123', string $name = 'cert.p12'): UploadedFile
{
    [$bundle] = makeTestPkcs12($password);

    return UploadedFile::fake()->createWithContent($name, $bundle);
}

/**
 * A platform admin who may operate workspaces from the SuperAdmin panel.
 * Shared because both the entitlement management and the subscriptions tests
 * need the same actor.
 */
function entitlementOperator(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny'] as $action) {
        Permission::firstOrCreate(['name' => "{$action}:Workspace", 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => "{$action}:WorkspaceEntitlement", 'guard_name' => 'web']);
    }

    $role = Role::firstOrCreate(['name' => 'entitlement_operator', 'guard_name' => 'web']);
    $role->syncPermissions(Permission::query()->get());

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Create a staff user with a profile (no Doctor, no personal workspace). */
function makeStaff(string $name, string $email): User
{
    $user = User::factory()->create(['account_type' => 'staff', 'email' => $email]);
    StaffProfile::create(['user_id' => $user->id, 'name' => $name]);

    return $user->fresh('staffProfile');
}

/**
 * Build a demo clinic with a doctor owner, a doctor member, a nurse (staff) and
 * a receptionist (staff). Returns every actor the multi-role tests need.
 *
 * @return array{clinic: Workspace, ownerUser: User, ownerDoctor: Doctor, memUser: User, memDoctor: Doctor, nurseUser: User, recUser: User}
 */
function makeClinicScenario(): array
{
    $svc = app(ClinicService::class);

    [$ownerDoctor, $ownerUser] = makeDoctorWithWorkspace();
    $clinic = $svc->createClinic($ownerDoctor, 'Clínica Test'); // owner = ownerDoctor

    [$memDoctor, $memUser] = makeDoctorWithWorkspace();
    $svc->attachMember($clinic, $memUser, 'doctor');

    $nurseUser = makeStaff('Enfermera Demo', 'nurse_'.uniqid().'@clinica.test');
    $svc->attachMember($clinic, $nurseUser, 'nurse');

    $recUser = makeStaff('Recepción Demo', 'rec_'.uniqid().'@clinica.test');
    $svc->attachMember($clinic, $recUser, 'receptionist');

    return compact('clinic', 'ownerUser', 'ownerDoctor', 'memUser', 'memDoctor', 'nurseUser', 'recUser');
}

/**
 * Set the full role set on a clinic member (multi-role). Uses the model so the
 * jsonb `roles` cast applies.
 *
 * @param  array<string, mixed>  $scenario  From makeClinicScenario().
 * @param  list<string>  $roles
 */
function setMemberRoles(array $scenario, string $userKey, array $roles): void
{
    $member = $scenario['clinic']->members()->where('user_id', $scenario[$userKey]->id)->first();
    $member->update(['roles' => $roles]);
}
