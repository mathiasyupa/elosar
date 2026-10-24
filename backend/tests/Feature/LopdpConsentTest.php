<?php

use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

// ─────────────────────────────────────────────
// C — LOPDP consent capture (server-side, not spoofable)
// ─────────────────────────────────────────────

it('stamps consent metadata server-side when data_consent is given', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = minimalPatientData([
        'data_consent'      => true,
        'consent_marketing' => true,
        // Attempt to spoof the audit fields — must be ignored.
        'data_consent_at'   => '2000-01-01 00:00:00',
    ]);

    $id = $this->postJson('/api/v1/patients', $payload, ['X-Workspace-Id' => (string) $ws->id])
        ->assertCreated()
        ->json('data.id');

    $patient = Patient::find($id);

    expect($patient->data_consent_at)->not->toBeNull()
        ->and($patient->data_consent_at->year)->toBe(now()->year) // not the spoofed 2000
        ->and($patient->data_consent_version)->toBe(config('lopdp.consent_version'))
        ->and($patient->data_consent_by_user_id)->toBe($user->id)
        ->and($patient->consent_marketing)->toBeTrue();
})->group('lopdp');

it('does not stamp consent when data_consent is absent', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/patients', minimalPatientData(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertCreated()
        ->json('data.id');

    expect(Patient::find($id)->data_consent_at)->toBeNull();
})->group('lopdp');

// ─────────────────────────────────────────────
// C — Right to erasure (anonymization) preserves the clinical record
// ─────────────────────────────────────────────

it('anonymizes patient PII while keeping the clinical record', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'name'         => 'Juan Real',
        'email'        => 'juan@real.com',
        'phone'        => '0991234567',
    ]);
    $record = \App\Models\MedicalRecord::factory()->create([
        'workspace_id' => $ws->id, 'patient_id' => $patient->id,
    ]);

    $this->artisan('patients:anonymize', ['id' => $patient->id, '--force' => true])
        ->assertSuccessful();

    // Assertions read across the tenant boundary (no active workspace in the
    // test), same as the CLI command context.
    app(\App\Support\ActiveWorkspace::class)->runUnguarded(function () use ($patient, $record) {
        $fresh = Patient::withTrashed()->find($patient->id);

        expect($fresh->name)->toBe('PACIENTE ANONIMIZADO')
            ->and($fresh->email)->not->toBe('juan@real.com')
            ->and($fresh->phone)->toBe('N/A')
            ->and($fresh->trashed())->toBeTrue()
            // Clinical record is preserved for legal retention.
            ->and(\App\Models\MedicalRecord::withTrashed()->find($record->id))->not->toBeNull();
    });
})->group('lopdp');
