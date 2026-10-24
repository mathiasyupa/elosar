<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Specialty;
use App\Services\PrescriptionService;
use Laravel\Sanctum\Sanctum;

it('snapshots the professional description the author writes', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $secondary = Specialty::factory()->create(['name' => 'Medicina General']);
    $doctor->specialties()->syncWithoutDetaching([$secondary->id => ['is_primary' => false]]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $headers = ['X-Workspace-Id' => (string) $workspace->id];
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control general',
        'finalize' => false,
        'professional_title' => str_repeat('x', 121),
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('professional_title');

    $created = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control general',
        'finalize' => false,
        'professional_title' => 'Medicina General',
    ], $headers)->assertCreated()->assertJsonPath('data.professional_title', 'Medicina General')->json('data');

    $record = MedicalRecord::findOrFail($created['id']);
    expect($record->professional_title)->toBe('Medicina General')
        ->and(app(PrescriptionService::class)->html($record))->toContain('Medicina General');

    // The document keeps its consultation snapshot if the profile changes.
    $doctor->specialties()->detach($secondary->id);
    expect(app(PrescriptionService::class)->html($record->fresh()))->toContain('Medicina General');

    // Written freely by the doctor, not limited to the profile's list.
    $this->putJson("/api/v1/records/{$record->id}", [
        'professional_title' => 'Médico Familiar',
        'finalize' => false,
    ], $headers)->assertOk()->assertJsonPath('data.professional_title', 'Médico Familiar');
});

it('defaults to the primary professional description and lets the author fix it after closing', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $headers = ['X-Workspace-Id' => (string) $workspace->id];
    Sanctum::actingAs($user);

    $created = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Diagnóstico de prueba',
        'treatment' => 'Plan de prueba',
    ], $headers)->assertCreated()->json('data');

    expect($created['professional_title'])->toBe($doctor->primarySpecialty()->name);

    $other = Specialty::factory()->create(['name' => 'Medicina General']);
    $doctor->specialties()->syncWithoutDetaching([$other->id => ['is_primary' => false]]);
    $this->putJson("/api/v1/records/{$created['id']}", [
        'finalize' => false,
    ], $headers)->assertUnprocessable()->assertJsonValidationErrors('finalize');
    // Editing a closed receta can correct how it is signed.
    $this->putJson("/api/v1/records/{$created['id']}", [
        'professional_title' => 'Medicina General',
    ], $headers)->assertOk()->assertJsonPath('data.professional_title', 'Medicina General');
});

it('lets only the author choose a different title on an existing draft', function () {
    $scenario = makeClinicScenario();
    $secondary = Specialty::factory()->create(['name' => 'Medicina General']);
    $scenario['ownerDoctor']->specialties()->syncWithoutDetaching([$secondary->id => ['is_primary' => false]]);
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    $record = MedicalRecord::create([
        'workspace_id' => $scenario['clinic']->id,
        'patient_id' => $patient->id,
        'created_by' => $scenario['ownerDoctor']->id,
        'status' => 'draft',
        'reason_for_consultation' => 'Control',
        'professional_title' => $scenario['ownerDoctor']->primarySpecialty()->name,
    ]);
    $headers = ['X-Workspace-Id' => (string) $scenario['clinic']->id];

    Sanctum::actingAs($scenario['memUser']);
    $this->putJson("/api/v1/records/{$record->id}", [
        'professional_title' => 'Medicina General', 'finalize' => false,
    ], $headers)->assertForbidden();
    expect($record->fresh()->professional_title)->toBe($scenario['ownerDoctor']->primarySpecialty()->name);

    Sanctum::actingAs($scenario['ownerUser']);
    $this->putJson("/api/v1/records/{$record->id}", [
        'professional_title' => 'Medicina General', 'finalize' => false,
    ], $headers)->assertOk()->assertJsonPath('data.professional_title', 'Medicina General');
});
