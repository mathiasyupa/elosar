<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordGrowthAssessment;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

function pediatricGrowthHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function pediatricGrowthPayload(Patient $patient, array $vitals = []): array
{
    return [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control pediátrico',
        'diagnosis' => 'Control de crecimiento',
        'treatment' => 'Seguimiento clínico',
        'consultation_type' => 'pediatric',
        'vitals' => array_merge([
            // WHO expanded tables, boys, day 0 medians.
            'weight_kg' => 3.3464,
            'height_cm' => 49.8842,
            'head_circumference_cm' => 34.4618,
            'length_height_measurement_position' => 'recumbent_length',
        ], $vitals),
    ];
}

beforeEach(function () {
    Carbon::setTestNow('2026-01-01 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('calculates and persists the three WHO 2006 indicators from server-side inputs', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $workspace->id,
        'birth_date' => '2026-01-01',
        'gender' => 'male',
    ]);
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', pediatricGrowthPayload($patient), pediatricGrowthHeaders($workspace->id))
        ->assertCreated()
        ->assertJsonPath('data.structured_exam_template_version', 2)
        ->json('data');

    expect($data['growth_assessments'])->toHaveCount(1)
        ->and($data['growth_assessments'][0]['status'])->toBe('calculated')
        ->and($data['growth_assessments'][0]['age_days'])->toBe(0)
        ->and($data['growth_assessments'][0]['sex'])->toBe('male')
        ->and($data['growth_assessments'][0]['standard']['source'])->toBe('WHO_CHILD_GROWTH_STANDARDS')
        ->and($data['growth_assessments'][0]['standard']['version'])->toBe('WHO-2006')
        ->and($data['growth_assessments'][0]['standard']['table_checksums'])->toHaveKey('weight_for_age:male');

    $indicators = collect($data['growth_assessments'][0]['indicators'])->keyBy('code');
    // Inputs are persisted at the product's two-decimal clinical precision;
    // these are the OMS LMS outputs for the resulting stored measurements.
    expect($indicators['weight_for_age']['z_score'])->toBe('0.01')
        ->and($indicators['weight_for_age']['percentile'])->toBe('50.3')
        ->and($indicators['length_height_for_age']['z_score'])->toBe('0.00')
        ->and($indicators['head_circumference_for_age']['z_score'])->toBe('0.00');

    $assessment = RecordGrowthAssessment::query()->where('medical_record_id', $data['id'])->firstOrFail();
    expect($assessment->record_vital_id)->toBe($data['vitals'][0]['id'])
        ->and($assessment->table_checksums['weight_for_age:male'])->toBe('b5b4748c6bfa5230e2eddafa1767629c349178b08d457f400b59422b8bfef86c');

    $this->assertDatabaseHas('audit_log', [
        'table_name' => 'record_growth_assessments',
        'record_id' => $assessment->id,
        'operation' => 'INSERT',
    ]);
});

it('does not let a client provide a calculated growth result', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $workspace->id,
        'birth_date' => '2026-01-01',
        'gender' => 'female',
    ]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', pediatricGrowthPayload($patient, [
        'growth_assessment' => ['percentile' => 99.9],
    ]), pediatricGrowthHeaders($workspace->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vitals.growth_assessment']);
});

it('keeps safe not-calculable states instead of inferring an unsupported sex', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $workspace->id,
        'birth_date' => '2026-01-01',
        'gender' => 'other',
    ]);
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', pediatricGrowthPayload($patient), pediatricGrowthHeaders($workspace->id))
        ->assertCreated()
        ->json('data.growth_assessments.0');

    expect($data['status'])->toBe('not_calculable')
        ->and($data['reason_code'])->toBe('sex_not_supported')
        ->and(collect($data['indicators'])->every(fn (array $indicator) => $indicator['status'] === 'not_calculable'))->toBeTrue();
});

it('calculates the independent indicators when position is missing but leaves length height unavailable', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $workspace->id,
        'birth_date' => '2026-01-01',
        'gender' => 'female',
    ]);
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', pediatricGrowthPayload($patient, [
        'length_height_measurement_position' => null,
    ]), pediatricGrowthHeaders($workspace->id))->assertCreated()->json('data.growth_assessments.0.indicators');

    $indicators = collect($data)->keyBy('code');
    expect($indicators['weight_for_age']['status'])->toBe('calculated')
        ->and($indicators['head_circumference_for_age']['status'])->toBe('calculated')
        ->and($indicators['length_height_for_age']['status'])->toBe('not_calculable')
        ->and($indicators['length_height_for_age']['reason_code'])->toBe('measurement_position_missing');
});

it('recalculates the same vital snapshot when a pediatric draft is completed', function () {
    [, $user, $workspace] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $workspace->id,
        'birth_date' => '2026-01-01',
        'gender' => 'male',
    ]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $workspace->id,
        'patient_id' => $patient->id,
        'status' => 'draft',
    ]);
    Sanctum::actingAs($user);

    $this->putJson("/api/v1/records/{$record->id}", [
        'consultation_type' => 'pediatric',
        'reason_for_consultation' => 'Control pediátrico',
        'diagnosis' => 'Control',
        'treatment' => 'Seguimiento',
        'vitals' => pediatricGrowthPayload($patient)['vitals'],
    ], pediatricGrowthHeaders($workspace->id))
        ->assertOk()
        ->assertJsonPath('data.structured_exam_template_version', 2)
        ->assertJsonPath('data.growth_assessments.0.status', 'calculated');
});
