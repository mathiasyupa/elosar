<?php

use App\Models\Doctor;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;

// ─────────────────────────────────────────────
// Without authentication
// ─────────────────────────────────────────────

it('returns null when there is no authenticated user', function () {
    expect(app(ActiveWorkspace::class)->id())->toBeNull();
});

it('returns null when the authenticated user has no doctor', function () {
    $user = User::factory()->create();
    test()->actingAs($user);

    expect(app(ActiveWorkspace::class)->id())->toBeNull();
});

// ─────────────────────────────────────────────
// Default workspace
// ─────────────────────────────────────────────

it('uses the doctor personal workspace when no session is active', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    test()->actingAs($user);

    $id = app(ActiveWorkspace::class)->id();

    expect($id)->toBe($doctor->workspace_id);
});

// ─────────────────────────────────────────────
// Workspace switching
// ─────────────────────────────────────────────

it('allows switching to a workspace the doctor can access', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create(['owner_doctor_id' => $doctor->id]);
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);

    test()->actingAs($user);

    app(ActiveWorkspace::class)->set($clinic->id);

    expect(app(ActiveWorkspace::class)->id())->toBe($clinic->id);
});

it('throws 403 when switching to an inaccessible workspace', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $other = Workspace::factory()->create();

    test()->actingAs($user);

    expect(fn () => app(ActiveWorkspace::class)->set($other->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

// ─────────────────────────────────────────────
// Available workspaces
// ─────────────────────────────────────────────

it('lists only the workspaces the doctor can access', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $clinic  = Workspace::factory()->clinic()->create(['owner_doctor_id' => $doctor->id]);
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);

    $unrelated = Workspace::factory()->create(); // must not appear

    test()->actingAs($user);

    $available = app(ActiveWorkspace::class)->available();

    expect($available->pluck('id'))
        ->toContain($doctor->workspace_id)
        ->toContain($clinic->id)
        ->not->toContain($unrelated->id);
});

// ─────────────────────────────────────────────
// Clear
// ─────────────────────────────────────────────

it('falls back to the personal workspace after clear', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $clinic = Workspace::factory()->clinic()->create(['owner_doctor_id' => $doctor->id]);
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);

    test()->actingAs($user);

    $active = app(ActiveWorkspace::class);
    $active->set($clinic->id);
    expect($active->id())->toBe($clinic->id);

    $active->clear();
    expect($active->id())->toBe($doctor->workspace_id);
});
