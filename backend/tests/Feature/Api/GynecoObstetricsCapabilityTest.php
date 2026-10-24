<?php

use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\Specialty;
use Database\Seeders\SpecialtySeeder;
use Laravel\Sanctum\Sanctum;

function gynecoCapabilityHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function grantGynecoObstetricsCapability(Doctor $doctor): Specialty
{
    $specialty = Specialty::query()->firstOrCreate(['name' => 'Ginecología y Obstetricia']);
    $specialty->capabilities = [Specialty::CAPABILITY_GYNECO_OBSTETRICS];
    $specialty->save();

    // The canonical specialty may be secondary: clinical access is the union of
    // approved specialties, not a string match against the primary one.
    $doctor->specialties()->syncWithoutDetaching([$specialty->id => ['is_primary' => false]]);

    return $specialty;
}

function gynecoCapabilityScenario(): array
{
    $scenario = makeClinicScenario();
    grantGynecoObstetricsCapability($scenario['ownerDoctor']);

    return $scenario;
}

it('seeds the capability only on the approved canonical specialty and keeps self-service rows closed', function () {
    app(SpecialtySeeder::class)->run();

    $canonical = Specialty::query()->where('name', 'Ginecología y Obstetricia')->sole();
    $other = Specialty::query()->where('name', 'Medicina Interna')->sole();

    expect($canonical->hasCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS))->toBeTrue()
        ->and($other->hasCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS))->toBeFalse();

    $this->postJson('/api/v1/specialties', [
        'name' => 'Ginecología funcional libre',
        'capabilities' => [Specialty::CAPABILITY_GYNECO_OBSTETRICS],
    ])->assertCreated();

    $free = Specialty::query()->where('name', 'Ginecología funcional libre')->sole();

    expect($free->capabilities)->toBe([])
        ->and($free->hasCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS))->toBeFalse();
});

it('allows a doctor with the approved specialty and rejects a doctor without it', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control ginecológico',
        'diagnosis' => 'Sana',
        'treatment' => 'Control anual',
        'consultation_type' => 'gynecological',
    ], gynecoCapabilityHeaders($workspace->id))->assertForbidden();

    // The ordinary record path remains available: this gate is clinical-module
    // specific, not a replacement for records.manage.
    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control general',
        'diagnosis' => 'Sana',
        'treatment' => 'Control anual',
        'consultation_type' => 'general',
    ], gynecoCapabilityHeaders($workspace->id))->assertCreated();

    grantGynecoObstetricsCapability($doctor);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control prenatal',
        'diagnosis' => 'Embarazo normal',
        'treatment' => 'Control en cuatro semanas',
        'consultation_type' => 'prenatal',
    ], gynecoCapabilityHeaders($workspace->id))->assertCreated();
});

it('exposes the derived module in the auth bootstrap, never the specialty name as a client rule', function () {
    [$doctor, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('doctor.clinical_modules', []);

    grantGynecoObstetricsCapability($doctor);

    $this->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('doctor.clinical_modules', [Specialty::CAPABILITY_GYNECO_OBSTETRICS]);
});

it('denies every dedicated gyneco-obstetric endpoint to an otherwise clinical doctor', function () {
    $scenario = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();
    Sanctum::actingAs($scenario['ownerUser']);
    $headers = gynecoCapabilityHeaders($scenario['clinic']->id);

    $this->getJson("/api/v1/patients/{$patient->id}/gyneco-history", $headers)->assertForbidden();
    $this->putJson("/api/v1/patients/{$patient->id}/gyneco-history", ['menarche_age' => 12], $headers)->assertForbidden();
    $this->getJson("/api/v1/patients/{$patient->id}/pregnancies", $headers)->assertForbidden();
    $this->postJson("/api/v1/patients/{$patient->id}/pregnancies", ['lmp_date' => now()->subDays(30)->toDateString()], $headers)->assertForbidden();
    $this->getJson("/api/v1/pregnancies/{$pregnancy->id}", $headers)->assertForbidden();
    $this->putJson("/api/v1/pregnancies/{$pregnancy->id}", ['risk_notes' => 'Control'], $headers)->assertForbidden();
    $this->postJson("/api/v1/pregnancies/{$pregnancy->id}/close", ['outcome' => 'term_birth', 'outcome_date' => now()->toDateString()], $headers)->assertForbidden();
    $this->deleteJson("/api/v1/pregnancies/{$pregnancy->id}", [], $headers)->assertForbidden();
    $this->getJson("/api/v1/pregnancies/{$pregnancy->id}/screenings", $headers)->assertForbidden();
    $this->postJson("/api/v1/pregnancies/{$pregnancy->id}/screenings", ['code' => 'blood_group', 'status' => 'done'], $headers)->assertForbidden();
    $this->getJson("/api/v1/pregnancies/{$pregnancy->id}/timeline", $headers)->assertForbidden();
});

it('does not let a staff account reach the specialty module by receiving a clinical role', function () {
    $scenario = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);

    // This role grants records.view. The account deliberately has no Doctor
    // profile, so the specialty gate must still deny it.
    setMemberRoles($scenario, 'nurseUser', ['doctor']);
    Sanctum::actingAs($scenario['nurseUser']);

    $this->getJson(
        "/api/v1/patients/{$patient->id}/gyneco-history",
        gynecoCapabilityHeaders($scenario['clinic']->id),
    )->assertForbidden();
});

it('keeps tenant isolation at 404 after the specialty gate allows the doctor', function () {
    $mine = gynecoCapabilityScenario();
    $theirs = gynecoCapabilityScenario();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $theirs['clinic']->id]);
    Sanctum::actingAs($mine['ownerUser']);

    $this->getJson(
        "/api/v1/patients/{$foreignPatient->id}/gyneco-history",
        gynecoCapabilityHeaders($mine['clinic']->id),
    )->assertNotFound();
});

it('denies edits to an existing specialized record without the capability', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'consultation_type' => 'prenatal',
        'status' => 'draft',
    ]);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/records/{$record->id}", [
        'recommendations' => 'Control',
    ], gynecoCapabilityHeaders($workspace->id))->assertForbidden();
});

it('does not let a doctor without the capability downgrade a specialized draft', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'consultation_type' => 'gynecological',
        'status' => 'draft',
        'structured_exam' => null,
    ]);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/records/{$record->id}", [
        'consultation_type' => 'general',
    ], gynecoCapabilityHeaders($workspace->id))->assertForbidden();
});

it('omits active pregnancy and the probable-deliveries worklist without the capability', function () {
    [$doctor, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    $pregnancy = Pregnancy::factory()->forPatient($patient)->create();
    Sanctum::actingAs($user);
    $headers = gynecoCapabilityHeaders($workspace->id);

    $patientData = $this->getJson("/api/v1/patients/{$patient->id}", $headers)
        ->assertOk()
        ->json('data');
    $reportData = $this->getJson('/api/v1/reports/clinical', $headers)
        ->assertOk()
        ->json('data');

    expect($patientData)->not->toHaveKey('active_pregnancy')
        ->and($reportData)->not->toHaveKey('probable_deliveries');

    grantGynecoObstetricsCapability($doctor);

    $patientData = $this->getJson("/api/v1/patients/{$patient->id}", $headers)
        ->assertOk()
        ->json('data');
    $reportData = $this->getJson('/api/v1/reports/clinical', $headers)
        ->assertOk()
        ->json('data');

    expect($patientData['active_pregnancy']['id'])->toBe($pregnancy->id)
        ->and($reportData['probable_deliveries']['count'])->toBe(1);
});

it('keeps active pregnancy out of a capable doctor session without records.view', function () {
    $scenario = makeClinicScenario();
    grantGynecoObstetricsCapability($scenario['memDoctor']);
    $patient = Patient::factory()->create(['workspace_id' => $scenario['clinic']->id]);
    Pregnancy::factory()->forPatient($patient)->create();
    $headers = gynecoCapabilityHeaders($scenario['clinic']->id);

    // This remains a Doctor account with the specialty, but operationally it
    // has reception's role in this clinic and therefore no clinical-record read.
    setMemberRoles($scenario, 'memUser', ['receptionist']);
    Sanctum::actingAs($scenario['memUser']);

    $data = $this->getJson("/api/v1/patients/{$patient->id}", $headers)
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKey('active_pregnancy');

    setMemberRoles($scenario, 'memUser', ['doctor']);

    $data = $this->getJson("/api/v1/patients/{$patient->id}", $headers)
        ->assertOk()
        ->json('data');

    expect($data['active_pregnancy'])->not->toBeNull();
});
