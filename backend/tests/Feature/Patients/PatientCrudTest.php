<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;

// ─────────────────────────────────────────────
// Creation
// ─────────────────────────────────────────────

it('creates a patient assigned to the active workspace', function () {
    $doctor = actingAsDoctor();

    $patient = Patient::create(minimalPatientData([
        'name'       => 'Juan Pérez',
        'id_number'  => '0912345678',
        'birth_date' => '1985-03-15',
    ]));

    expect($patient->workspace_id)->toBe($doctor->workspace_id)
        ->and(Patient::count())->toBe(1);
});

it('doctor only sees their own patients after creating', function () {
    [$doctor1] = makeDoctorWithWorkspace();
    [$doctor2] = makeDoctorWithWorkspace();

    actingAsDoctor($doctor1);
    Patient::create(minimalPatientData(['name' => 'Paciente Doctor1', 'id_number' => '1111111111']));

    // Switch to doctor2
    actingAsDoctor($doctor2);
    Patient::create(minimalPatientData(['name' => 'Paciente Doctor2', 'id_number' => '2222222222']));

    // Back to doctor1 — must only see their own patient
    actingAsDoctor($doctor1);

    expect(Patient::all())->toHaveCount(1)
        ->and(Patient::first()->name)->toBe('Paciente Doctor1');
});

// ─────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────

it('can update a patient from their workspace', function () {
    $doctor  = actingAsDoctor();
    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id, 'name' => 'Nombre Original']);

    $patient->update(['name' => 'Nombre Actualizado']);

    expect($patient->fresh()->name)->toBe('Nombre Actualizado');
});

// ─────────────────────────────────────────────
// Deletion
// ─────────────────────────────────────────────

it('can delete a patient from their workspace', function () {
    $doctor  = actingAsDoctor();
    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id]);

    $patient->delete();

    expect(Patient::count())->toBe(0);
});

// ─────────────────────────────────────────────
// Doctor workspace auto-creation
// ─────────────────────────────────────────────

it('a new doctor gets a personal workspace generated automatically', function () {
    $user   = \App\Models\User::factory()->create();
    $doctor = Doctor::factory()->withUser($user)->create();
    $doctor->refresh();

    expect($doctor->workspace_id)->not->toBeNull()
        ->and($doctor->workspace)->toBeInstanceOf(Workspace::class)
        ->and($doctor->workspace->type)->toBe('personal')
        ->and($doctor->workspace->owner_doctor_id)->toBe($doctor->id);
});
