<?php

use App\Models\Patient;
use App\Services\ClinicService;
use App\Support\LetterheadData;
use Laravel\Sanctum\Sanctum;

/*
| Role-based authorization across the clinic API. Tenant isolation is covered
| elsewhere (ApiAuthAndIsolationTest); here we assert that the permission matrix
| (WorkspacePermissionService) is enforced by the EnsurePermission middleware on
| real routes, for doctors, staff and owners. makeClinicScenario() / makeStaff()
| live in tests/Pest.php (shared across the multi-role suites).
*/

/** X-Workspace-Id header selecting the active workspace. */
function roleHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Receptionist
// ─────────────────────────────────────────────

it('forbids a receptionist from reading a medical record but allows listing patients', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['recUser']);

    // records.view is denied → 403 before the record is even resolved.
    $this->getJson('/api/v1/records/1', $h)->assertStatus(403);

    // patients.view is granted.
    $this->getJson('/api/v1/patients', $h)->assertOk();
});

// ─────────────────────────────────────────────
// Nurse
// ─────────────────────────────────────────────

it('forbids a nurse from creating a full consultation', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    // records.manage is denied → 403 regardless of payload (no dx slips through).
    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'diagnoses' => [['cie10_code' => 'J00', 'type' => 'primary']],
    ], $h)->assertStatus(403);
});

it('lets a nurse list patients (demographics access)', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $this->getJson('/api/v1/patients', $h)->assertOk();
});

// ─────────────────────────────────────────────
// Ownership overlay (independent of role)
// ─────────────────────────────────────────────

it('lets a nurse who owns the clinic manage settings and letterhead', function () {
    $s = makeClinicScenario();
    $s['clinic']->update(['owner_user_id' => $s['nurseUser']->id, 'owner_doctor_id' => null]);
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    $this->getJson('/api/v1/settings', $h)->assertOk();

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header' => 'Clínica Test S.A.'],
    ], $h)->assertOk();
});

it('lets a nurse who is not the owner read settings but never write them', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['nurseUser']);

    // Every member can see how the clinic's paper and look are configured...
    $this->getJson('/api/v1/settings', $h)->assertOk();

    // ...and the invite code stays with the owner.
    expect($this->getJson('/api/v1/settings', $h)->json('data'))->not->toHaveKey('invite_code');

    $this->putJson('/api/v1/settings', ['razon_social' => 'Hack'], $h)->assertStatus(403);
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header' => 'Hack'],
    ], $h)->assertStatus(403);
});

it('lets reception and the cashier read settings but never write them', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);

    foreach (['receptionist', 'billing'] as $role) {
        $staff = makeStaff('Staff '.$role, $role.'_'.uniqid().'@clinica.test');
        app(ClinicService::class)->attachMember($s['clinic'], $staff, $role);

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/settings', $h)->assertOk();
        $this->putJson('/api/v1/settings', ['razon_social' => 'Hack'], $h)->assertStatus(403);
    }
});

it('lets a doctor member read settings but forbids writing them', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);
    Sanctum::actingAs($s['memUser']);

    // Doctors can view the clinic config (settings.view) ...
    $this->getJson('/api/v1/settings', $h)->assertOk();

    // ... but not change it — that is owner-only (settings.manage).
    $this->putJson('/api/v1/settings', ['razon_social' => 'Hack'], $h)->assertStatus(403);
});

// ─────────────────────────────────────────────
// Bootstrap shape
// ─────────────────────────────────────────────

it('does not list any personal workspace for a staff account', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);

    $data = $this->getJson('/api/v1/workspaces')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['type'])->toBe('clinic')
        ->and($data[0]['id'])->toBe($s['clinic']->id);
});

it('returns role and permissions per workspace in /auth/me for staff', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['recUser']);

    $me = $this->getJson('/api/v1/auth/me')->assertOk()->json();

    expect($me['user']['account_type'])->toBe('staff')
        ->and($me['doctor'])->toBeNull()
        ->and($me['staff_profile'])->not->toBeNull();

    $ws = collect($me['workspaces'])->firstWhere('id', $s['clinic']->id);
    expect($ws['membership_role'])->toBe('receptionist')
        ->and($ws['is_owner'])->toBeFalse()
        ->and($ws['permissions'])->toContain('patients.view')
        ->and($ws['permissions'])->not->toContain('records.view');
});

it('keeps the solo doctor personal workspace with full permissions (regression)', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $me = $this->getJson('/api/v1/auth/me')->assertOk()->json();

    $personal = collect($me['workspaces'])->firstWhere('type', 'personal');
    expect($personal)->not->toBeNull()
        ->and($personal['is_owner'])->toBeTrue()
        ->and($personal['membership_role'])->toBe('doctor')
        ->and($personal['permissions'])->toContain('records.manage')
        ->and($personal['permissions'])->toContain('settings.manage')
        ->and($personal['permissions'])->toContain('billing.manage');
});

it('lets reception set up the clinic paper but not the fiscal data', function () {
    $s = makeClinicScenario();
    $h = roleHeaders($s['clinic']->id);

    $desk = makeStaff('Recepcion Papel', 'papel_'.uniqid().'@clinica.test');
    app(ClinicService::class)->attachMember($s['clinic'], $desk, 'receptionist');
    Sanctum::actingAs($desk);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header_mode' => 'image', 'header_image_height' => 40],
    ], $h)->assertOk();

    expect($s['clinic']->fresh()->prescription_settings['header_image_height'])->toBe(40);
    $this->putJson('/api/v1/settings', ['razon_social' => 'Hack'], $h)->assertStatus(403);

    // The next doctor to print finds it already loaded: the paper is the clinic's.
    expect(LetterheadData::for($s['clinic']->fresh(), null)['headerImageStyle'])
        ->toBe('max-height: 40mm');
});

it('keeps nursing from changing the clinic paper', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['nurseUser']);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header' => 'Hack'],
    ], roleHeaders($s['clinic']->id))->assertStatus(403);
});

it('rejects a banner height outside what fits on the page', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser'] ?? $s['memUser']);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['header_image_height' => 500],
    ], roleHeaders($s['clinic']->id))->assertStatus(422);
});
