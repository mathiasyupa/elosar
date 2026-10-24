<?php

use App\Exceptions\WorkspaceContextMissingException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;

// ─────────────────────────────────────────────
// Patient isolation across workspaces
// ─────────────────────────────────────────────

it('doctor only sees patients from their workspace', function () {
    [$doctor1, , $ws1] = makeDoctorWithWorkspace();
    [$doctor2, , $ws2] = makeDoctorWithWorkspace();

    // Insert directly (without scope) to simulate another tenant's data
    Patient::withoutGlobalScopes()->create(minimalPatientData([
        'workspace_id' => $ws1->id,
        'name'         => 'Paciente WS1',
        'id_number'    => '1111111111',
    ]));
    Patient::withoutGlobalScopes()->create(minimalPatientData([
        'workspace_id' => $ws2->id,
        'name'         => 'Paciente WS2',
        'id_number'    => '2222222222',
    ]));

    actingAsDoctor($doctor1);

    $visible = Patient::all();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->name)->toBe('Paciente WS1');
});

it('doctor cannot see patients from another workspace even knowing the ID', function () {
    [$doctor1, , $ws1] = makeDoctorWithWorkspace();
    [$doctor2, , $ws2] = makeDoctorWithWorkspace();

    $patientOther = Patient::withoutGlobalScopes()->create(minimalPatientData([
        'workspace_id' => $ws2->id,
        'name'         => 'Paciente Ajeno',
        'id_number'    => '9999999999',
        'birth_date'   => '1985-05-05',
    ]));

    actingAsDoctor($doctor1);

    $found = Patient::find($patientOther->id);

    expect($found)->toBeNull();
});

// ─────────────────────────────────────────────
// Medical record isolation
// ─────────────────────────────────────────────

it('doctor only sees medical records from their workspace', function () {
    [$doctor1, , $ws1] = makeDoctorWithWorkspace();
    [$doctor2, , $ws2] = makeDoctorWithWorkspace();

    $patient1 = Patient::factory()->create(['workspace_id' => $ws1->id]);
    $patient2 = Patient::factory()->create(['workspace_id' => $ws2->id]);

    MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $ws1->id,
        'patient_id'              => $patient1->id,
        'created_by'              => $doctor1->id,
        'reason_for_consultation' => 'Consulta WS1',
        'diagnosis'               => 'Dx WS1',
        'treatment'               => 'Tratamiento WS1',
    ]);
    MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $ws2->id,
        'patient_id'              => $patient2->id,
        'created_by'              => $doctor2->id,
        'reason_for_consultation' => 'Consulta WS2',
        'diagnosis'               => 'Dx WS2',
        'treatment'               => 'Tratamiento WS2',
    ]);

    actingAsDoctor($doctor1);

    $records = MedicalRecord::all();

    expect($records)->toHaveCount(1)
        ->and($records->first()->reason_for_consultation)->toBe('Consulta WS1');
});

// ─────────────────────────────────────────────
// Appointment isolation
// ─────────────────────────────────────────────

it('doctor only sees appointments from their workspace', function () {
    [$doctor1, , $ws1] = makeDoctorWithWorkspace();
    [$doctor2, , $ws2] = makeDoctorWithWorkspace();

    $patient1 = Patient::factory()->create(['workspace_id' => $ws1->id]);
    $patient2 = Patient::factory()->create(['workspace_id' => $ws2->id]);

    Appointment::withoutGlobalScopes()->create([
        'workspace_id'     => $ws1->id,
        'patient_id'       => $patient1->id,
        'doctor_id'        => $doctor1->id,
        'appointment_date' => now()->addDays(3),
        'status'           => 'scheduled',
    ]);
    Appointment::withoutGlobalScopes()->create([
        'workspace_id'     => $ws2->id,
        'patient_id'       => $patient2->id,
        'doctor_id'        => $doctor2->id,
        'appointment_date' => now()->addDays(5),
        'status'           => 'scheduled',
    ]);

    actingAsDoctor($doctor1);

    expect(Appointment::all())->toHaveCount(1);
});

// ─────────────────────────────────────────────
// Active workspace switching
// ─────────────────────────────────────────────

it('doctor can switch to a clinic workspace they belong to', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    $clinic          = Workspace::factory()->clinic()->create(['owner_doctor_id' => $doctor->id]);

    // Link doctor to the clinic
    $doctor->clinics()->syncWithoutDetaching([$clinic->id]);

    actingAsDoctor($doctor);

    $active = app(ActiveWorkspace::class);
    $active->set($clinic->id);

    expect($active->id())->toBe($clinic->id);
});

it('doctor cannot switch to a workspace they do not belong to', function () {
    [$doctor] = makeDoctorWithWorkspace();
    $otherWorkspace = Workspace::factory()->create();

    actingAsDoctor($doctor);

    expect(fn () => app(ActiveWorkspace::class)->set($otherWorkspace->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

// ─────────────────────────────────────────────
// Fail-closed: no resolvable workspace
// ─────────────────────────────────────────────

it('throws instead of leaking when querying a scoped model with no active workspace', function () {
    Workspace::factory()->create();

    // No actingAs, no binding, not unguarded → fail-closed.
    expect(fn () => Patient::all())
        ->toThrow(WorkspaceContextMissingException::class);
});

it('sees every tenant inside runUnguarded()', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();

    Patient::withoutGlobalScopes()->insert([
        minimalPatientData(['workspace_id' => $ws1->id, 'name' => 'P1', 'id_number' => '1111111111', 'birth_date' => '1990-01-01', 'created_at' => now(), 'updated_at' => now()]),
        minimalPatientData(['workspace_id' => $ws2->id, 'name' => 'P2', 'id_number' => '2222222222', 'birth_date' => '1991-01-01', 'created_at' => now(), 'updated_at' => now()]),
    ]);

    $all = app(ActiveWorkspace::class)->runUnguarded(fn () => Patient::all());

    expect($all)->toHaveCount(2);
});

it('filters by a stateless workspace binding without a session', function () {
    $ws1 = Workspace::factory()->create();
    $ws2 = Workspace::factory()->create();

    Patient::withoutGlobalScopes()->insert([
        minimalPatientData(['workspace_id' => $ws1->id, 'name' => 'P1', 'id_number' => '1111111111', 'birth_date' => '1990-01-01', 'created_at' => now(), 'updated_at' => now()]),
        minimalPatientData(['workspace_id' => $ws2->id, 'name' => 'P2', 'id_number' => '2222222222', 'birth_date' => '1991-01-01', 'created_at' => now(), 'updated_at' => now()]),
    ]);

    $visible = app(ActiveWorkspace::class)->runBound($ws2->id, fn () => Patient::all());

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->name)->toBe('P2');
});
