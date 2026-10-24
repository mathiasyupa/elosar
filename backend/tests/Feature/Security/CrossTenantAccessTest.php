<?php

use App\Models\FamilyBackground;
use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\Medication;
use App\Models\Patient;
use App\Models\PatientAllergy;
use App\Models\Study;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * G1 — adversarial cross-tenant access.
 *
 * The existing isolation tests (WorkspaceIsolationTest, ApiAuthAndIsolationTest)
 * attack Patient, MedicalRecord and Appointment: models that carry
 * BelongsToWorkspace and therefore cannot leak, because the global scope filters
 * them whether or not the controller remembers to.
 *
 * This file attacks the opposite set — the child models with NO scope of their
 * own, whose isolation depends entirely on the controller reaching them through
 * a scoped parent:
 *
 *   PatientAllergy · FamilyBackground · RecordVital · RecordDiagnosis
 *   RecordPrescription · WorkspaceMember · DoctorCommissionRule
 *
 * Today every one of those controllers is written correctly. That is exactly
 * why these tests exist: correct-by-convention is one careless refactor away
 * from a leak, and nothing would fail until a doctor read another clinic's
 * patient. The nastiest shape is deliberately covered below — a caller who owns
 * the PARENT and supplies a FOREIGN CHILD id, which no workspace scope catches.
 */
function crossTenantHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/**
 * A second clinic, fully populated, that the attacker has no relationship with.
 *
 * @return array{workspace: Workspace, patient: Patient, record: MedicalRecord, allergy: PatientAllergy, background: FamilyBackground}
 */
function foreignClinic(): array
{
    [$doctor, , $workspace] = makeDoctorWithWorkspace();

    $patient = Patient::withoutGlobalScopes()->create(
        minimalPatientData(['workspace_id' => $workspace->id])
    );

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'reason_for_consultation' => 'Dolor torácico',
        'diagnosis' => 'Confidencial',
        'treatment' => 'Confidencial',
    ]);

    $allergy = PatientAllergy::create([
        'patient_id' => $patient->id,
        'allergen_type' => 'drug',
        'allergen_name' => 'Penicilina',
        'severity' => 'severe',
    ]);

    $background = FamilyBackground::create([
        'patient_id' => $patient->id,
        'relative_type' => 'mother',
        'disease' => 'Diabetes',
    ]);

    return compact('workspace', 'patient', 'record', 'allergy', 'background');
}

// ─────────────────────────────────────────────
// A — Unscoped children reached through a parent the attacker DOES own.
//     This is the shape a workspace scope cannot catch on its own.
// ─────────────────────────────────────────────

it('refuses to delete an allergy belonging to another clinic through an owned patient', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $ownPatient = Patient::withoutGlobalScopes()->create(
        minimalPatientData(['workspace_id' => $ws->id])
    );

    $this->deleteJson(
        "/api/v1/patients/{$ownPatient->id}/allergies/{$foreign['allergy']->id}",
        [],
        crossTenantHeaders($ws->id)
    )->assertNotFound();

    expect(PatientAllergy::find($foreign['allergy']->id))->not->toBeNull();
})->group('security');

it('refuses to delete a family background belonging to another clinic through an owned patient', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $ownPatient = Patient::withoutGlobalScopes()->create(
        minimalPatientData(['workspace_id' => $ws->id])
    );

    $this->deleteJson(
        "/api/v1/patients/{$ownPatient->id}/family-background/{$foreign['background']->id}",
        [],
        crossTenantHeaders($ws->id)
    )->assertNotFound();

    expect(FamilyBackground::find($foreign['background']->id))->not->toBeNull();
})->group('security');

// ─────────────────────────────────────────────
// B — Foreign parent ids, straight from the URL.
// ─────────────────────────────────────────────

it('refuses every read of a foreign patient and their clinical children', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $headers = crossTenantHeaders($ws->id);
    $patientId = $foreign['patient']->id;
    $recordId = $foreign['record']->id;

    $this->getJson("/api/v1/patients/{$patientId}", $headers)->assertNotFound();
    $this->getJson("/api/v1/patients/{$patientId}/records", $headers)->assertNotFound();
    $this->getJson("/api/v1/patients/{$patientId}/baseline-vitals", $headers)->assertNotFound();
    $this->getJson("/api/v1/records/{$recordId}", $headers)->assertNotFound();
    $this->getJson("/api/v1/records/{$recordId}/orders", $headers)->assertNotFound();
})->group('security');

it('refuses to write vitals onto a foreign clinical record', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // The controller runs findOrFail() before validating, so a foreign id is
    // rejected as not-found rather than leaking "this record exists but your
    // payload is wrong" through a 422.
    $this->patchJson(
        "/api/v1/records/{$foreign['record']->id}/vitals",
        ['blood_pressure_sys' => 120, 'blood_pressure_dia' => 80],
        crossTenantHeaders($ws->id)
    )->assertNotFound();
})->group('security');

it('refuses to update the clinical template of a foreign record', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // The scoped parent must resolve before the new template validation. A
    // foreign id is therefore still invisible, even with a valid F1 payload.
    $this->putJson(
        "/api/v1/records/{$foreign['record']->id}",
        ['structured_exam' => ['cabeza' => 'No debe escribirse']],
        crossTenantHeaders($ws->id),
    )->assertNotFound();
})->group('security');

it('refuses to print a prescription PDF from a foreign clinical record', function () {
    $foreign = foreignClinic();
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // A PDF leak is a leak: the document carries the diagnosis and the patient's
    // name even when the JSON endpoints are locked down.
    $this->get(
        "/api/v1/records/{$foreign['record']->id}/prescription",
        crossTenantHeaders($ws->id)
    )->assertNotFound();
})->group('security');

it('refuses to download a foreign clinical attachment', function () {
    Storage::fake('local');
    $foreign = foreignClinic();

    $path = 'attachments/foreign-'.uniqid().'.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 confidential');

    $attachment = MedicalAttachment::withoutGlobalScopes()->create([
        'workspace_id' => $foreign['workspace']->id,
        'medical_record_id' => $foreign['record']->id,
        'patient_id' => $foreign['patient']->id,
        'title' => 'Radiografía',
        'file_path' => $path,
    ]);

    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->get("/api/v1/attachments/{$attachment->id}/download", crossTenantHeaders($ws->id))
        ->assertNotFound();
})->group('security');

// ─────────────────────────────────────────────
// C — Attacking the tenant selector itself.
// ─────────────────────────────────────────────

it('refuses a workspace the caller does not belong to, even with a valid token', function () {
    $foreign = foreignClinic();
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // Pointing X-Workspace-Id at someone else's clinic must not switch tenant.
    $this->getJson('/api/v1/patients', crossTenantHeaders($foreign['workspace']->id))
        ->assertForbidden();
})->group('security');

it('refuses a workspace id that does not exist at all', function () {
    [, $user] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/patients', crossTenantHeaders(999999))
        ->assertForbidden();
})->group('security');

// ─────────────────────────────────────────────
// D — Clinic administration: members and commission rules.
// ─────────────────────────────────────────────

it('refuses to manage a team member of another clinic', function () {
    // Both actors own a clinic, so the attacker genuinely holds team.manage in
    // their OWN workspace. That isolates what is under test: crossing tenants,
    // not lacking a permission. A 403 here would prove nothing.
    $victim = makeClinicScenario();
    $attacker = makeClinicScenario();

    $foreignMember = $victim['clinic']->members()->firstOrFail();

    Sanctum::actingAs($attacker['ownerUser']);
    $headers = crossTenantHeaders($attacker['clinic']->id);

    $this->putJson("/api/v1/clinic/team/{$foreignMember->id}", ['status' => 'inactive'], $headers)
        ->assertNotFound();

    $this->getJson("/api/v1/clinic/team/{$foreignMember->id}/commission-rules", $headers)
        ->assertNotFound();

    expect($foreignMember->fresh()->status)->not->toBe('inactive');
})->group('security');

// ─────────────────────────────────────────────
// E — Shared catalogues (Study, Medication).
//     Deliberately unscoped: a null workspace_id row is global and every clinic
//     must see it. That means the ONLY thing standing between clinics is the
//     explicit ->where('workspace_id', …) in CatalogController. Pinned here
//     because WorkspaceScopeGuardTest exempts these two by name.
// ─────────────────────────────────────────────

it('refuses to delete a catalogue study belonging to another clinic', function () {
    [, , $victimWs] = makeDoctorWithWorkspace();
    $study = Study::factory()->create(['workspace_id' => $victimWs->id]);

    [, $attacker, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($attacker);

    $this->deleteJson("/api/v1/studies/{$study->id}", [], crossTenantHeaders($ws->id))
        ->assertNotFound();

    expect(Study::find($study->id))->not->toBeNull();
})->group('security');

it('refuses to delete a catalogue medication belonging to another clinic', function () {
    [, , $victimWs] = makeDoctorWithWorkspace();
    $medication = Medication::create([
        'workspace_id' => $victimWs->id,
        'name' => 'Amoxicilina',
    ]);

    [, $attacker, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($attacker);

    $this->deleteJson("/api/v1/medications/{$medication->id}", [], crossTenantHeaders($ws->id))
        ->assertNotFound();

    expect(Medication::find($medication->id))->not->toBeNull();
})->group('security');

it('still lets a clinic delete its own catalogue entry', function () {
    // Control: proves the 404s above come from tenant filtering, not from the
    // endpoint being broken for everyone.
    [, $user, $ws] = makeDoctorWithWorkspace();
    $study = Study::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->deleteJson("/api/v1/studies/{$study->id}", [], crossTenantHeaders($ws->id))
        ->assertNoContent();
})->group('security');

it('lets the same call succeed inside the caller own clinic', function () {
    // The counterpart to the test above: if 404 were coming from a broken route
    // rather than from tenant isolation, this would fail too — and the isolation
    // test would be proving nothing.
    $own = makeClinicScenario();
    $member = $own['clinic']->members()
        ->where('user_id', $own['memUser']->id)
        ->firstOrFail();

    Sanctum::actingAs($own['ownerUser']);

    $this->getJson(
        "/api/v1/clinic/team/{$member->id}/commission-rules",
        crossTenantHeaders($own['clinic']->id)
    )->assertOk();
})->group('security');
