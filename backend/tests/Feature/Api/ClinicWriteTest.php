<?php

use App\Models\Doctor;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Laravel\Sanctum\Sanctum;

/*
| Clinic lifecycle endpoints. These run under `auth:sanctum` WITHOUT the
| `workspace` middleware (they bootstrap workspace selection), so no
| X-Workspace-Id header is sent. Isolation here is by explicit ownership /
| membership, not WorkspaceScope.
*/

// ─────────────────────────────────────────────
// Create
// ─────────────────────────────────────────────

it('creates a clinic and makes the doctor its owner and member', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/clinics', ['name' => 'Clínica San Rafael'])
        ->assertStatus(201)
        ->assertJsonPath('data.type', 'clinic')
        ->assertJsonPath('data.name', 'Clínica San Rafael')
        ->json('data');

    // Owner recorded on the workspace.
    $this->assertDatabaseHas('workspaces', [
        'id'              => $data['id'],
        'type'            => 'clinic',
        'owner_doctor_id' => $doctor->id,
    ]);

    // Active membership created for the owner.
    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $data['id'],
        'doctor_id'    => $doctor->id,
        'status'       => 'active',
    ]);
});

it('lists a newly created clinic in GET /workspaces', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Central'])
        ->assertStatus(201)
        ->json('data.id');

    $ids = collect(
        $this->getJson('/api/v1/workspaces')->assertOk()->json('data')
    )->pluck('id');

    expect($ids)->toContain($personal->id)
        ->and($ids)->toContain($clinicId);
});

it('rejects creating a clinic without a name', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/clinics', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// ─────────────────────────────────────────────
// Join
// ─────────────────────────────────────────────

it('joins a clinic by invite code and becomes a member', function () {
    // Owner doctor creates the clinic.
    [$owner, $ownerUser] = makeDoctorWithWorkspace();
    Sanctum::actingAs($ownerUser);
    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Norte'])
        ->assertStatus(201)
        ->json('data.id');
    $inviteCode = Workspace::findOrFail($clinicId)->invite_code;

    // A second doctor joins using that code.
    [$joiner, $joinerUser] = makeDoctorWithWorkspace();
    Sanctum::actingAs($joinerUser);

    $this->postJson('/api/v1/clinics/join', ['invite_code' => $inviteCode])
        ->assertOk()
        ->assertJsonPath('data.id', $clinicId);

    $this->assertDatabaseHas('workspace_members', [
        'workspace_id' => $clinicId,
        'doctor_id'    => $joiner->id,
        'status'       => 'active',
    ]);

    // The joined clinic now shows up in the joiner's accessible workspaces.
    $ids = collect(
        $this->getJson('/api/v1/workspaces')->assertOk()->json('data')
    )->pluck('id');
    expect($ids)->toContain($clinicId);
});

it('rejects joining with an invalid invite code', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/clinics/join', ['invite_code' => 'NOPE0000'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('invite_code');
});

// ─────────────────────────────────────────────
// Invite code — owner only
// ─────────────────────────────────────────────

it('lets the owner read the invite code', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Sur'])->json('data.id');

    $code = $this->getJson("/api/v1/clinics/{$clinicId}/invite-code")
        ->assertOk()
        ->json('invite_code');

    expect($code)->toBe(Workspace::findOrFail($clinicId)->invite_code);
});

it('lets the owner regenerate the invite code, invalidating the previous one', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Este'])->json('data.id');
    $oldCode  = Workspace::findOrFail($clinicId)->invite_code;

    $newCode = $this->postJson("/api/v1/clinics/{$clinicId}/regenerate-code")
        ->assertOk()
        ->json('invite_code');

    expect($newCode)->not->toBe($oldCode);
    expect(Workspace::findOrFail($clinicId)->invite_code)->toBe($newCode);
});

it('forbids a non-owner doctor from reading the invite code', function () {
    // Owner creates the clinic.
    [, $ownerUser] = makeDoctorWithWorkspace();
    Sanctum::actingAs($ownerUser);
    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Ajena'])->json('data.id');

    // A different doctor (not the owner) is forbidden, even after joining.
    [$other, $otherUser] = makeDoctorWithWorkspace();
    $inviteCode = Workspace::findOrFail($clinicId)->invite_code;
    Sanctum::actingAs($otherUser);
    $this->postJson('/api/v1/clinics/join', ['invite_code' => $inviteCode])->assertOk();

    $this->getJson("/api/v1/clinics/{$clinicId}/invite-code")->assertStatus(403);
});

it('forbids a non-owner doctor from regenerating the invite code', function () {
    [, $ownerUser] = makeDoctorWithWorkspace();
    Sanctum::actingAs($ownerUser);
    $clinicId = $this->postJson('/api/v1/clinics', ['name' => 'Clínica Protegida'])->json('data.id');
    $oldCode  = Workspace::findOrFail($clinicId)->invite_code;

    [, $otherUser] = makeDoctorWithWorkspace();
    Sanctum::actingAs($otherUser);

    $this->postJson("/api/v1/clinics/{$clinicId}/regenerate-code")->assertStatus(403);

    // Code is untouched.
    expect(Workspace::findOrFail($clinicId)->invite_code)->toBe($oldCode);
});

it('returns 404 for the invite code of a personal (non-clinic) workspace', function () {
    [, $user, $personal] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // The owner's personal workspace is type=personal — not addressable here.
    $this->getJson("/api/v1/clinics/{$personal->id}/invite-code")->assertNotFound();
});
