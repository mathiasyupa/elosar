<?php

use App\Models\Doctor;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Clinic-administrator self-registration
|--------------------------------------------------------------------------
| A non-doctor owner registers, creating User(staff) + StaffProfile + a clinic
| they own (membership role 'admin'). No Doctor, no personal workspace. The
| owner overlay grants settings/team management but never clinical records.
*/

function clinicAdminPayload(array $overrides = []): array
{
    return array_merge([
        'name'         => 'Gerente Demo',
        'email'        => 'gerente_' . uniqid() . '@clinica.test',
        'password'     => 'password123',
        'clinic_name'  => 'Centro Médico Demo',
        'phone'        => '+593999999999',
        'accept_terms' => true,
    ], $overrides);
}

it('registers a non-doctor clinic administrator with a clinic they own', function () {
    $payload = clinicAdminPayload();

    // The clinic is provisioned, but no session comes back: the address has to
    // answer first (see EmailVerificationApiTest).
    $this->postJson('/api/v1/auth/register/clinic-admin', $payload)
        ->assertStatus(201)
        ->assertJsonPath('requires_verification', true)
        ->assertJsonMissingPath('token');

    $user = User::where('email', $payload['email'])->firstOrFail();

    expect($user->account_type)->toBe('staff');

    // No Doctor, has a staff profile.
    expect(Doctor::where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->staffProfile)->not->toBeNull();

    // Exactly one workspace: the clinic they own. No personal workspace. Read
    // from the database now that the response no longer carries the list.
    expect(WorkspaceMember::where('user_id', $user->id)->count())->toBe(1);

    $clinic = Workspace::where('type', 'clinic')->where('name', $payload['clinic_name'])->firstOrFail();
    expect((int) $clinic->owner_user_id)->toBe($user->id)
        ->and($clinic->owner_doctor_id)->toBeNull();

    // Active admin membership keyed by the user.
    $membership = WorkspaceMember::where('workspace_id', $clinic->id)->where('user_id', $user->id)->first();
    expect($membership)->not->toBeNull()
        ->and($membership->role)->toBe('admin')
        ->and($membership->status)->toBe('active');
});

it('gives the owner operations + config permissions but NOT clinical records', function () {
    $payload = clinicAdminPayload();
    $this->postJson('/api/v1/auth/register/clinic-admin', $payload)->assertStatus(201);

    $user = User::where('email', $payload['email'])->firstOrFail();
    Sanctum::actingAs($user);

    $ws = collect($this->getJson('/api/v1/auth/me')->assertOk()->json('workspaces'))->first();
    $perms = $ws['permissions'];

    expect($perms)->toContain('settings.manage')
        ->and($perms)->toContain('team.manage')
        ->and($perms)->toContain('billing.manage')
        ->and($perms)->toContain('appointments.manage')
        // Financial reports yes, clinical (patient-level diagnoses) no.
        ->and($perms)->toContain('reports.view')
        ->and($perms)->not->toContain('reports.clinical')
        // Non-doctor administrator never sees clinical records.
        ->and($perms)->not->toContain('records.view')
        ->and($perms)->not->toContain('records.manage');
});

it('forbids a non-doctor owner from clinical reports but allows financial ones (LOPDP)', function () {
    $payload = clinicAdminPayload();
    $this->postJson('/api/v1/auth/register/clinic-admin', $payload)->assertStatus(201);

    $user = User::where('email', $payload['email'])->firstOrFail();
    $clinic = Workspace::where('type', 'clinic')->where('name', $payload['clinic_name'])->firstOrFail();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $clinic->id];

    // Patient-level clinical / epidemiological data is off-limits to a non-doctor.
    $this->getJson('/api/v1/reports/clinical', $h)->assertForbidden();
    $this->getJson('/api/v1/reports/rdacaa', $h)->assertForbidden();
    // Financial reports are fine — the administrator runs the money side.
    $this->getJson('/api/v1/reports/revenue', $h)->assertOk();
});

it('rejects a duplicate email', function () {
    $payload = clinicAdminPayload();
    $this->postJson('/api/v1/auth/register/clinic-admin', $payload)->assertStatus(201);

    $this->postJson('/api/v1/auth/register/clinic-admin', clinicAdminPayload(['email' => $payload['email']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('requires the clinic name', function () {
    $this->postJson('/api/v1/auth/register/clinic-admin', clinicAdminPayload(['clinic_name' => '']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('clinic_name');
});

it('lets the administrator owner invite a doctor and a receptionist', function () {
    $payload = clinicAdminPayload();
    $this->postJson('/api/v1/auth/register/clinic-admin', $payload)->assertStatus(201);

    $user = User::where('email', $payload['email'])->firstOrFail();
    $clinic = Workspace::where('type', 'clinic')->where('name', $payload['clinic_name'])->firstOrFail();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $clinic->id];

    $this->postJson('/api/v1/clinic/invite', ['email' => 'medico_'.uniqid().'@x.test', 'role' => 'doctor'], $h)
        ->assertStatus(201);
    $this->postJson('/api/v1/clinic/invite', ['email' => 'rec_'.uniqid().'@x.test', 'role' => 'receptionist'], $h)
        ->assertStatus(201);
});
