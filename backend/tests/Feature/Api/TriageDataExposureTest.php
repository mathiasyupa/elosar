<?php

use App\Models\Appointment;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

it('returns nursing measurements without disclosing the consultation narrative', function () {
    $scenario = makeClinicScenario();
    $clinic = $scenario['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $clinic->id]);
    $appointment = Appointment::create([
        'workspace_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $scenario['memDoctor']->id,
        'appointment_date' => now(),
        'status' => 'checked_in',
    ]);
    $headers = ['X-Workspace-Id' => (string) $clinic->id];

    Sanctum::actingAs($scenario['nurseUser']);
    $draft = $this->postJson('/api/v1/records/draft', [
        'appointment_id' => $appointment->id,
    ], $headers)->assertCreated()->json('data');

    expect(array_keys($draft))->toEqualCanonicalizing([
        'id', 'appointment_id', 'patient_id', 'status', 'patient', 'vitals',
    ]);
    expect(array_keys($draft['patient']))->toEqualCanonicalizing(['id', 'name']);

    $saved = $this->patchJson("/api/v1/records/{$draft['id']}/vitals", [
        'weight_kg' => 70,
        'heart_rate' => 80,
    ], $headers)->assertOk()->json('data');

    expect(array_keys($saved))->toEqualCanonicalizing([
        'id', 'appointment_id', 'patient_id', 'status', 'patient', 'vitals',
    ]);
    expect($saved['vitals'][0]['heart_rate'])->toBe(80);

    // Another measurement remains allowed while the draft is in vitals_done.
    $this->patchJson("/api/v1/records/{$draft['id']}/vitals", [
        'heart_rate' => 82,
    ], $headers)->assertOk()->assertJsonPath('data.vitals.0.heart_rate', 82);

    $record = \App\Models\MedicalRecord::findOrFail($draft['id']);
    $record->update(['status' => 'completed', 'diagnosis' => 'Texto confidencial']);
    $this->patchJson("/api/v1/records/{$draft['id']}/vitals", [
        'heart_rate' => 90,
    ], $headers)->assertStatus(409);
    expect($record->fresh()->vitals()->first()->heart_rate)->toBe(82);

    $record->update(['status' => 'draft', 'voided_at' => now()]);
    $this->patchJson("/api/v1/records/{$draft['id']}/vitals", [
        'heart_rate' => 90,
    ], $headers)->assertStatus(409);
    expect($record->fresh()->vitals()->first()->heart_rate)->toBe(82);
});

it('does not create another nursing draft for a finished appointment or an active visit', function () {
    $scenario = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    $appointment = Appointment::create([
        'workspace_id' => $scenario['clinic']->id,
        'patient_id' => $patient->id,
        'doctor_id' => $scenario['memDoctor']->id,
        'appointment_date' => now(),
        'status' => 'completed',
    ]);
    $headers = ['X-Workspace-Id' => (string) $scenario['clinic']->id];
    Sanctum::actingAs($scenario['nurseUser']);

    $this->postJson('/api/v1/records/draft', ['appointment_id' => $appointment->id], $headers)
        ->assertStatus(409);

    $appointment->update(['status' => 'checked_in']);
    \App\Models\MedicalRecord::create([
        'workspace_id' => $scenario['clinic']->id,
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
        'created_by' => $scenario['memDoctor']->id,
        'status' => 'in_progress',
    ]);
    $this->postJson('/api/v1/records/draft', ['appointment_id' => $appointment->id], $headers)
        ->assertStatus(409);
    expect(\App\Models\MedicalRecord::query()->where('appointment_id', $appointment->id)->count())->toBe(1);

    $active = \App\Models\MedicalRecord::query()->where('appointment_id', $appointment->id)->firstOrFail();
    $active->update(['status' => 'draft']);
    $appointment->update(['status' => 'completed']);
    $this->postJson('/api/v1/records/draft', ['appointment_id' => $appointment->id], $headers)
        ->assertStatus(409);
    $this->patchJson("/api/v1/records/{$active->id}/vitals", ['heart_rate' => 90], $headers)
        ->assertStatus(409);
    expect($active->fresh()->vitals()->count())->toBe(0);
});
