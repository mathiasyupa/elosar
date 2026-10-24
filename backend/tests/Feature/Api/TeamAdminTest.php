<?php

use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
| Team administration: owner invites staff, staff register via token, owner
| changes roles / deactivates members. Actors from makeClinicScenario().
*/

function teamHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

// ─────────────────────────────────────────────
// Invitations (owner only)
// ─────────────────────────────────────────────

it('lets the owner invite a staff member by email + role', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);

    $data = $this->postJson('/api/v1/clinic/invite', [
        'email' => 'nuevo@clinica.test',
        'role' => 'receptionist',
    ], teamHeaders($s['clinic']->id))
        ->assertStatus(201)
        ->json('data');

    expect($data['token'])->not->toBeEmpty()
        ->and($data['role'])->toBe('receptionist');

    $this->assertDatabaseHas('clinic_invites', [
        'workspace_id' => $s['clinic']->id,
        'email' => 'nuevo@clinica.test',
        'role' => 'receptionist',
    ]);
});

it('forbids a doctor member from inviting staff', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['memUser']);

    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'x@clinica.test', 'role' => 'nurse',
    ], teamHeaders($s['clinic']->id))->assertStatus(403);
});

it('rejects inviting with an unknown role', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);

    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'x@clinica.test', 'role' => 'superadmin',
    ], teamHeaders($s['clinic']->id))->assertStatus(422);
});

it('lets the owner invite a doctor by email + role', function () {
    $s = makeClinicScenario();
    // The scenario clinic already runs its two included doctors, and this test
    // is about the invitation itself, not about billing a third seat.
    $s['clinic']->entitlement()->update(['clinical_seat_limit' => 3]);
    Sanctum::actingAs($s['ownerUser']);

    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'medico@clinica.test', 'role' => 'doctor',
    ], teamHeaders($s['clinic']->id))
        ->assertStatus(201)
        ->assertJsonPath('data.role', 'doctor');

    $this->assertDatabaseHas('clinic_invites', [
        'workspace_id' => $s['clinic']->id,
        'email' => 'medico@clinica.test',
        'role' => 'doctor',
    ]);
});

// ─────────────────────────────────────────────
// Staff registration via invite token
// ─────────────────────────────────────────────

it('registers a staff account from an invite — no doctor, no personal workspace', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $token = $this->postJson('/api/v1/clinic/invite', [
        'email' => 'recepcion@clinica.test', 'role' => 'receptionist',
    ], teamHeaders($s['clinic']->id))->json('data.token');

    // Public registration (no auth).
    app('auth')->forgetGuards();
    $resp = $this->postJson('/api/v1/auth/register/staff', [
        'token' => $token, 'name' => 'Nueva Recepción', 'password' => 'password123',
        'accept_terms' => true,
    ])->assertStatus(201)->json();

    expect($resp['user']['account_type'])->toBe('staff')
        ->and($resp['doctor'])->toBeNull()
        ->and($resp['staff_profile']['name'])->toBe('Nueva Recepción')
        ->and($resp['workspaces'])->toHaveCount(1)
        ->and($resp['workspaces'][0]['type'])->toBe('clinic')
        ->and($resp['workspaces'][0]['membership_role'])->toBe('receptionist');

    $user = User::where('email', 'recepcion@clinica.test')->first();
    expect($user->doctor)->toBeNull()
        ->and($user->account_type)->toBe('staff');

    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $s['clinic']->id,
        'user_id' => $user->id,
        'role' => 'receptionist',
        'status' => 'active',
    ]);
});

it('registers a real doctor from a doctor invite — Doctor, personal workspace, clinic membership', function () {
    $s = makeClinicScenario();
    $specialty = Specialty::factory()->create();

    // The scenario clinic already runs the two doctors its plan includes, and
    // this test is about the invitation flow, not about billing a third seat.
    $s['clinic']->entitlement()->update(['clinical_seat_limit' => 3]);

    Sanctum::actingAs($s['ownerUser']);
    $token = $this->postJson('/api/v1/clinic/invite', [
        'email' => 'nuevo.medico@clinica.test', 'role' => 'doctor',
    ], teamHeaders($s['clinic']->id))->json('data.token');

    // Public registration (no auth).
    app('auth')->forgetGuards();
    $resp = $this->postJson('/api/v1/auth/register/staff', [
        'token' => $token,
        'name' => 'Dra. Nueva Médica',
        'password' => 'password123',
        'id_number' => '1710034065', // valid Ecuadorian cedula (mod-10)
        'phone' => '0991234567',
        'city' => 'Quito',
        'specialty_ids' => [$specialty->id],
        'accept_terms' => true,
    ])->assertStatus(201)->json();

    expect($resp['user']['account_type'])->toBe('doctor')
        ->and($resp['doctor']['name'])->toBe('Dra. Nueva Médica')
        ->and($resp['staff_profile'])->toBeNull();

    $user = User::where('email', 'nuevo.medico@clinica.test')->first();
    $doctor = Doctor::where('user_id', $user->id)->first();
    expect($doctor)->not->toBeNull()
        ->and($doctor->workspace->type)->toBe('personal')
        ->and($doctor->specialty_id)->toBe($specialty->id);

    // Joined the inviting clinic as a doctor member (full clinical role).
    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $s['clinic']->id,
        'user_id' => $user->id,
        'role' => 'doctor',
        'status' => 'active',
    ]);

    // The bootstrap payload exposes both the clinic and the personal workspace,
    // and the clinic membership carries the doctor role.
    $clinicWs = collect($resp['workspaces'])->firstWhere('id', $s['clinic']->id);
    expect($clinicWs['membership_role'])->toBe('doctor');
});

it('rejects a doctor invite redemption missing clinical fields', function () {
    $s = makeClinicScenario();
    $s['clinic']->entitlement()->update(['clinical_seat_limit' => 3]);
    Sanctum::actingAs($s['ownerUser']);
    $token = $this->postJson('/api/v1/clinic/invite', [
        'email' => 'incompleto@clinica.test', 'role' => 'doctor',
    ], teamHeaders($s['clinic']->id))->json('data.token');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $token, 'name' => 'Sin Datos', 'password' => 'password123',
        'accept_terms' => true,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['id_number', 'city', 'specialty_ids']);
});

it('rejects reusing an accepted invite token', function () {
    $s = makeClinicScenario();
    Sanctum::actingAs($s['ownerUser']);
    $token = $this->postJson('/api/v1/clinic/invite', [
        'email' => 'once@clinica.test', 'role' => 'nurse',
    ], teamHeaders($s['clinic']->id))->json('data.token');

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $token, 'name' => 'Uno', 'password' => 'password123',
        'accept_terms' => true,
    ])->assertStatus(201);

    $this->postJson('/api/v1/auth/register/staff', [
        'token' => $token, 'name' => 'Dos', 'password' => 'password123',
    ])->assertStatus(422);
});

// ─────────────────────────────────────────────
// Member role / status management
// ─────────────────────────────────────────────

it('lets the owner change a member role and deactivate them', function () {
    $s = makeClinicScenario();
    $member = $s['clinic']->members()->where('user_id', $s['nurseUser']->id)->first();
    Sanctum::actingAs($s['ownerUser']);

    $this->putJson("/api/v1/clinic/team/{$member->id}", ['roles' => ['admin']], teamHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data.role', 'admin')
        ->assertJsonPath('data.roles', ['admin']);

    $this->putJson("/api/v1/clinic/team/{$member->id}", ['status' => 'inactive'], teamHeaders($s['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');
});

it('protects the owner from being deactivated', function () {
    $s = makeClinicScenario();
    // Make the owner a member row too (createClinic already added the owner doctor).
    $ownerMember = $s['clinic']->members()->where('user_id', $s['ownerUser']->id)->first();
    Sanctum::actingAs($s['ownerUser']);

    $this->putJson("/api/v1/clinic/team/{$ownerMember->id}", ['status' => 'inactive'], teamHeaders($s['clinic']->id))
        ->assertStatus(422);
});

it('forbids a doctor member from managing the team', function () {
    $s = makeClinicScenario();
    $member = $s['clinic']->members()->where('user_id', $s['nurseUser']->id)->first();
    Sanctum::actingAs($s['memUser']);

    $this->putJson("/api/v1/clinic/team/{$member->id}", ['roles' => ['billing']], teamHeaders($s['clinic']->id))
        ->assertStatus(403);
});
