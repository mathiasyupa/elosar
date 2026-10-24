<?php

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\ActiveWorkspace;
use Laravel\Sanctum\Sanctum;

it('limits workspace lists and direct API access to the locked clinic', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create();
    $otherClinic = Workspace::factory()->clinic()->create();
    $doctor->clinics()->syncWithoutDetaching([$clinic->id, $otherClinic->id]);
    WorkspaceMember::create([
        'user_id' => $user->id,
        'doctor_id' => $doctor->id,
        'workspace_id' => $clinic->id,
        'role' => 'doctor',
        'roles' => ['doctor'],
        'status' => 'active',
    ]);
    $user->forceFill(['workspace_access_lock_id' => $clinic->id])->save();

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
    expect(collect($login->json('workspaces'))->pluck('id')->all())->toBe([$clinic->id]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/workspaces')->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$clinic->id]);
    expect(collect($this->getJson('/api/v1/auth/me')->assertOk()->json('workspaces'))
        ->pluck('id')->all())->toBe([$clinic->id]);

    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $personal->id])
        ->assertForbidden();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $otherClinic->id])
        ->assertForbidden();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $clinic->id])
        ->assertOk();

    expect(Workspace::find($personal->id))->not->toBeNull();
});

it('does not grant a locked workspace without a valid access relationship', function () {
    [, $user, $personal] = makeDoctorWithWorkspace();
    $foreignClinic = Workspace::factory()->clinic()->create();
    $user->forceFill(['workspace_access_lock_id' => $foreignClinic->id])->save();

    Sanctum::actingAs($user);

    expect(app(ActiveWorkspace::class)->available())->toBeEmpty();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $foreignClinic->id])
        ->assertForbidden();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $personal->id])
        ->assertForbidden();
});

it('uses the locked clinic when an existing personal workspace selection expires', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create();
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);
    WorkspaceMember::create([
        'user_id' => $user->id,
        'doctor_id' => $doctor->id,
        'workspace_id' => $clinic->id,
        'role' => 'doctor',
        'roles' => ['doctor'],
        'status' => 'active',
    ]);
    $user->forceFill(['workspace_access_lock_id' => $clinic->id])->save();

    $this->actingAs($user);
    session(['active_workspace_id' => $personal->id]);

    expect(app(ActiveWorkspace::class)->id())->toBe($clinic->id);
});

it('keeps the normal personal and clinic access for unlocked users', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create();
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);

    Sanctum::actingAs($user);

    expect(app(ActiveWorkspace::class)->available()->pluck('id')->all())
        ->toContain($personal->id, $clinic->id);
});

it('revokes a locked clinic when its membership is inactive despite a stale doctor pivot', function () {
    [$doctor, $user, $personal] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create();
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);
    WorkspaceMember::create([
        'user_id' => $user->id,
        'doctor_id' => $doctor->id,
        'workspace_id' => $clinic->id,
        'role' => 'doctor',
        'roles' => ['doctor'],
        'status' => 'inactive',
    ]);
    $user->forceFill(['workspace_access_lock_id' => $clinic->id])->save();

    Sanctum::actingAs($user);

    expect(app(ActiveWorkspace::class)->available())->toBeEmpty();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $clinic->id])
        ->assertForbidden();
    $this->getJson('/api/v1/appointments', ['X-Workspace-Id' => (string) $personal->id])
        ->assertForbidden();
});

it('blocks clinic creation joining and management outside the locked clinic', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create([
        'owner_doctor_id' => $doctor->id,
        'owner_user_id' => $user->id,
    ]);
    $otherOwned = Workspace::factory()->clinic()->create([
        'owner_doctor_id' => $doctor->id,
        'owner_user_id' => $user->id,
    ]);
    WorkspaceMember::create([
        'user_id' => $user->id,
        'doctor_id' => $doctor->id,
        'workspace_id' => $clinic->id,
        'role' => 'doctor',
        'roles' => ['doctor'],
        'status' => 'active',
    ]);
    $user->forceFill(['workspace_access_lock_id' => $clinic->id])->save();

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/clinics', ['name' => 'Clínica adicional'])->assertForbidden();
    $this->postJson('/api/v1/clinics/join', ['invite_code' => $otherOwned->invite_code])
        ->assertForbidden();
    $this->getJson("/api/v1/clinics/{$otherOwned->id}/invite-code")->assertForbidden();
    $this->postJson("/api/v1/clinics/{$otherOwned->id}/regenerate-code")
        ->assertForbidden();
    $this->getJson("/api/v1/clinics/{$clinic->id}/invite-code")->assertOk();
});
