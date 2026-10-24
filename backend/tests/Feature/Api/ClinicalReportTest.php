<?php

use App\Models\Cie10Code;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\Workspace;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

function clinicalHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function grantClinicalReportGynecoCapability(App\Models\Doctor $doctor): void
{
    $specialty = Specialty::query()->firstOrCreate(['name' => 'Ginecología y Obstetricia']);
    $specialty->capabilities = [Specialty::CAPABILITY_GYNECO_OBSTETRICS];
    $specialty->save();

    $doctor->specialties()->syncWithoutDetaching([$specialty->id => ['is_primary' => false]]);
}

function clinicalReportGynecoScenario(): array
{
    $scenario = makeClinicScenario();
    grantClinicalReportGynecoCapability($scenario['ownerDoctor']);

    return $scenario;
}

it('returns clinical report blocks scoped to the workspace, with morbidity and FPP', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    grantClinicalReportGynecoCapability($doctor);

    // ICD-10 catalog row so the chapter/system grouping can resolve a category.
    Cie10Code::create([
        'code' => 'J06.9',
        'description' => 'Infección aguda de las vías respiratorias superiores',
        'category' => 'Enfermedades respiratorias',
    ]);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // Completed attention with a primary diagnosis + a prescription.
    $rec1 = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'status' => 'completed',
        'consultation_type' => 'general',
    ]);
    $rec1->diagnoses()->create(['cie10_code' => 'J06.9', 'cie10_description' => 'IRA superior', 'type' => 'primary']);
    $rec1->prescriptions()->create([
        'medication_name' => 'Paracetamol',
        'generic_name' => 'Paracetamol',
        'dosage' => '1 tableta',
        'frequency' => 'cada 8 horas',
    ]);

    // Prenatal attention carrying a FUM → drives the probable delivery date.
    $fum = now()->subWeeks(10)->toDateString();
    MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'status' => 'completed',
        'consultation_type' => 'prenatal',
        'structured_exam' => ['fum' => $fum],
    ]);

    // Foreign workspace clinical data that must NOT leak in.
    $otherWs = Workspace::factory()->create();
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    $foreign = MedicalRecord::factory()->create([
        'workspace_id' => $otherWs->id,
        'patient_id' => $otherPatient->id,
        'status' => 'completed',
    ]);
    $foreign->diagnoses()->create(['cie10_code' => 'A00.0', 'cie10_description' => 'Cólera', 'type' => 'primary']);

    Sanctum::actingAs($user);

    $data = $this->getJson('/api/v1/reports/clinical', clinicalHeaders($ws->id))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'period' => ['from', 'to'],
            'morbidity' => [['code', 'description', 'total']],
            'by_system' => [['system', 'total']],
            'attentions' => ['total', 'monthly', 'by_type', 'by_doctor'],
            'demographics' => ['by_gender', 'by_age_group', 'by_city', 'new_patients', 'returning_patients'],
            'medications' => [['medication', 'total', 'controlled']],
            'vaccine_reminders' => ['count', 'items'],
            'probable_deliveries' => ['count', 'items'],
        ]])
        ->json('data');

    expect($data['morbidity'])->toHaveCount(1)                         // foreign A00.0 excluded
        ->and($data['morbidity'][0]['code'])->toBe('J06.9')
        ->and($data['morbidity'][0]['total'])->toBe(1)
        ->and($data['by_system'][0]['system'])->toBe('Enfermedades respiratorias')
        ->and($data['attentions']['total'])->toBe(2)
        ->and($data['medications'][0]['medication'])->toBe('Paracetamol')
        ->and($data['probable_deliveries']['count'])->toBe(1)
        ->and($data['probable_deliveries']['items'][0]['fum'])->toBe($fum)
        ->and($data['probable_deliveries']['items'][0]['edd'])->toBe(
            now()->subWeeks(10)->addDays(280)->toDateString()
        );
});

it('gates clinical reports on reports.clinical: cashier 403, doctor/owner 200', function () {
    $scenario = makeClinicScenario();
    $svc = app(ClinicService::class);
    $billingUser = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    $svc->attachMember($scenario['clinic'], $billingUser, 'billing');

    $headers = clinicalHeaders($scenario['clinic']->id);

    // Cashier (billing) must NOT see clinical/epidemiological data (LOPDP).
    Sanctum::actingAs($billingUser);
    $this->getJson('/api/v1/reports/clinical', $headers)->assertForbidden();
    $this->getJson('/api/v1/reports/rdacaa', $headers)->assertForbidden();

    // Owner (doctor-owner) and a member doctor can.
    Sanctum::actingAs($scenario['ownerUser']);
    $this->getJson('/api/v1/reports/clinical', $headers)->assertOk();

    Sanctum::actingAs($scenario['memUser']);
    $this->getJson('/api/v1/reports/clinical', $headers)->assertOk();
});

/*
|--------------------------------------------------------------------------
| Fechas probables de parto — el reporte híbrido
|--------------------------------------------------------------------------
| A registered pregnancy wins over the FUM buried in a closed consultation, and
| a patient who has both must not appear twice. Nothing is rewritten: the legacy
| JSONB stays where it is and keeps working for whoever has not been migrated.
*/

it('prefers the registered pregnancy over the legacy FUM, and never lists a patient twice', function () {
    $s = clinicalReportGynecoScenario();
    $ws = $s['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    // The old world: a closed prenatal consultation carrying a FUM.
    MedicalRecord::factory()->create([
        'workspace_id'      => $ws->id,
        'patient_id'        => $patient->id,
        'status'            => 'completed',
        'consultation_type' => 'prenatal',
        'structured_exam'   => ['fum' => now()->subWeeks(30)->toDateString()],
    ]);

    // The new one: a pregnancy dated differently, so the two cannot be confused.
    $lmp = now()->subWeeks(12)->toDateString();
    App\Models\Pregnancy::factory()->forPatient($patient)->create([
        'lmp_date'      => $lmp,
        'dating_source' => 'lmp',
        ...App\Services\Obstetrics\GestationalAge::columnsFor(
            ['lmp_date' => $lmp, 'dating_source' => 'lmp']
        ),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $deliveries = $this->getJson('/api/v1/reports/clinical', clinicalHeaders($ws->id))
        ->assertOk()
        ->json('data.probable_deliveries');

    expect($deliveries['count'])->toBe(1)
        ->and($deliveries['items'][0]['origin'])->toBe('pregnancy')
        ->and($deliveries['items'][0]['fum'])->toBe($lmp)
        ->and($deliveries['items'][0]['weeks'])->toBe(12);
});

it('still lists a patient whose only record is the legacy FUM, and says so', function () {
    $s = clinicalReportGynecoScenario();
    $ws = $s['clinic'];
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    $fum = now()->subWeeks(20)->toDateString();
    MedicalRecord::factory()->create([
        'workspace_id'      => $ws->id,
        'patient_id'        => $patient->id,
        'status'            => 'completed',
        'consultation_type' => 'prenatal',
        'structured_exam'   => ['fum' => $fum],
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $deliveries = $this->getJson('/api/v1/reports/clinical', clinicalHeaders($ws->id))
        ->assertOk()
        ->json('data.probable_deliveries');

    expect($deliveries['count'])->toBe(1)
        // The badge the screen needs to offer "open a pregnancy from this FUM".
        ->and($deliveries['items'][0]['origin'])->toBe('legacy_exam')
        ->and($deliveries['items'][0]['fum'])->toBe($fum);
});

/**
 * The old inline `intdiv(diffInDays, 7)` and the service must not disagree, or
 * the same woman reads as a different gestational age depending on which half of
 * the report she landed in.
 */
it('counts weeks the same way on both paths', function () {
    $s = clinicalReportGynecoScenario();
    $ws = $s['clinic'];
    $lmp = now()->subDays(150)->toDateString(); // 21+3

    $legacy = Patient::factory()->create(['workspace_id' => $ws->id]);
    MedicalRecord::factory()->create([
        'workspace_id'    => $ws->id,
        'patient_id'      => $legacy->id,
        'status'          => 'completed',
        'structured_exam' => ['fum' => $lmp],
    ]);

    $registered = Patient::factory()->create(['workspace_id' => $ws->id]);
    App\Models\Pregnancy::factory()->forPatient($registered)->create([
        'lmp_date'      => $lmp,
        'dating_source' => 'lmp',
        ...App\Services\Obstetrics\GestationalAge::columnsFor(
            ['lmp_date' => $lmp, 'dating_source' => 'lmp']
        ),
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $items = collect($this->getJson('/api/v1/reports/clinical', clinicalHeaders($ws->id))
        ->assertOk()
        ->json('data.probable_deliveries.items'));

    expect($items)->toHaveCount(2)
        ->and($items->pluck('weeks')->unique()->values()->all())->toBe([21]);
});

it('carries the columns the worklist needs to be usable', function () {
    $s = clinicalReportGynecoScenario();
    $ws = $s['clinic'];
    $patient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'birth_date'   => now()->subYears(29)->toDateString(),
        'phone'        => '0999999999',
    ]);
    App\Models\Pregnancy::factory()->forPatient($patient)->create();

    Sanctum::actingAs($s['ownerUser']);

    $item = $this->getJson('/api/v1/reports/clinical', clinicalHeaders($ws->id))
        ->assertOk()
        ->json('data.probable_deliveries.items.0');

    expect($item['age'])->toBe(29)
        ->and($item['phone'])->toBe('0999999999')
        ->and($item['dating'])->toBe('lmp');
});

it('keeps the FPP worklist clinic-wide when the clinical report is filtered by doctor', function () {
    $s = clinicalReportGynecoScenario();
    $ws = $s['clinic'];

    $registered = Patient::factory()->create(['workspace_id' => $ws->id]);
    App\Models\Pregnancy::factory()->forPatient($registered)->create();

    $legacy = Patient::factory()->create(['workspace_id' => $ws->id]);
    MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $legacy->id,
        'created_by' => $s['memDoctor']->id,
        'status' => 'completed',
        'consultation_type' => 'prenatal',
        'structured_exam' => ['fum' => now()->subWeeks(14)->toDateString()],
    ]);

    Sanctum::actingAs($s['ownerUser']);

    $items = $this->getJson('/api/v1/reports/clinical?doctor_id='.$s['ownerDoctor']->id, clinicalHeaders($ws->id))
        ->assertOk()
        ->json('data.probable_deliveries.items');

    expect(collect($items)->pluck('patient_id')->sort()->values()->all())
        ->toBe([$registered->id, $legacy->id]);
});
