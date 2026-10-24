<?php

use App\Models\AccessLog;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

/**
 * The "primeros pasos" checklist must reflect real data and the caller's real
 * permissions — a step may never be reported as done unless the underlying
 * record exists.
 */
function onboardingHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** Step keys, in order, as returned for the caller. */
function stepKeys(array $payload): array
{
    return array_column($payload['steps'], 'key');
}

/** Whether a given step is reported done. */
function stepDone(array $payload, string $key): bool
{
    foreach ($payload['steps'] as $step) {
        if ($step['key'] === $key) {
            return $step['done'];
        }
    }

    throw new RuntimeException("Step {$key} not present");
}

it('gives a solo doctor the five clinical steps, all pending on a brand new account', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $payload = $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))
        ->assertOk()
        ->json();

    expect(stepKeys($payload))->toBe([
        'letterhead',
        'first_patient',
        'first_appointment',
        'first_consultation',
        'first_prescription',
    ]);
    expect($payload['completed'])->toBe(0);
    expect($payload['total'])->toBe(5);
    expect($payload['dismissed'])->toBeFalse();
});

it('marks the patient step done only once a patient actually exists', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $before = $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json();
    expect(stepDone($before, 'first_patient'))->toBeFalse();

    Patient::factory()->create(['workspace_id' => $ws->id]);

    $after = $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json();
    expect(stepDone($after, 'first_patient'))->toBeTrue();
    expect($after['completed'])->toBe(1);
});

it('does not count another workspace data as progress', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $otherWs] = makeDoctorWithWorkspace();

    // Everything exists, but in someone else's workspace.
    $otherPatient = Patient::factory()->create(['workspace_id' => $otherWs->id]);
    Appointment::factory()->create([
        'workspace_id' => $otherWs->id,
        'patient_id'   => $otherPatient->id,
    ]);

    Sanctum::actingAs($user);
    $payload = $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json();

    expect(stepDone($payload, 'first_patient'))->toBeFalse();
    expect(stepDone($payload, 'first_appointment'))->toBeFalse();
    expect($payload['completed'])->toBe(0);
});

it('counts a consultation only when it is completed, not while it is a draft', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $draft = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_by'   => $doctor->id,
        'status'       => 'draft',
    ]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_consultation',
    ))->toBeFalse();

    $draft->update(['status' => 'completed']);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_consultation',
    ))->toBeTrue();
});

it('derives the prescription step from the access log, not from a client flag', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    // The letterhead is the OTHER half of this step; configure it so this test
    // is about the access log alone.
    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);
    Sanctum::actingAs($user);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_prescription',
    ))->toBeFalse();

    // A record being viewed is not a prescription being generated.
    AccessLog::create([
        'user_id'      => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'medical_record',
        'subject_id'   => 1,
        'action'       => 'view',
        'occurred_at'  => now(),
    ]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_prescription',
    ))->toBeFalse();

    AccessLog::create([
        'user_id'      => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'medical_record',
        'subject_id'   => 1,
        'action'       => 'prescription_pdf',
        'occurred_at'  => now(),
    ]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_prescription',
    ))->toBeTrue();
});

it('does not call the prescription step done while the receta would print blank', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    AccessLog::create([
        'user_id'      => $user->id,
        'workspace_id' => $ws->id,
        'subject_type' => 'medical_record',
        'subject_id'   => 1,
        'action'       => 'prescription_pdf',
        'occurred_at'  => now(),
    ]);

    // The PDF was generated, but on a blank page — the checklist must not claim
    // the doctor has a receta "lista para entregar".
    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_prescription',
    ))->toBeFalse();

    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'first_prescription',
    ))->toBeTrue();
});

it('does not hold a member doctor to a letterhead they cannot configure', function () {
    $scenario = makeClinicScenario();

    AccessLog::create([
        'user_id'      => $scenario['memUser']->id,
        'workspace_id' => $scenario['clinic']->id,
        'subject_type' => 'medical_record',
        'subject_id'   => 1,
        'action'       => 'prescription_pdf',
        'occurred_at'  => now(),
    ]);

    Sanctum::actingAs($scenario['memUser']);
    $payload = $this->getJson('/api/v1/onboarding', onboardingHeaders($scenario['clinic']->id))->json();

    // No settings.manage, so no letterhead step — and no hidden requirement
    // hanging off one either.
    expect(stepKeys($payload))->not->toContain('letterhead');
    expect(stepDone($payload, 'first_prescription'))->toBeTrue();
});

it('marks the letterhead done when a header line was typed', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeFalse();

    // Blank settings still count as unconfigured.
    $ws->update(['prescription_settings' => ['header' => '   ']]);
    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeFalse();

    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);
    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeTrue();
});

it('counts a designed sheet as a letterhead, header fields or not', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    // A sheet the clinic printed behind the content: the receta comes out on
    // the clinic's own designed paper, and the system letterhead is deliberately
    // suppressed (LetterheadData). Calling that "sin membrete" is a false alarm.
    $ws->update([
        'sheet_image_path'      => 'letterheads/demo.jpg',
        'prescription_settings' => ['sheet_mode' => 'background'],
    ]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeTrue();

    // "margins": the clinic prints on its physical pre-designed paper, so we
    // print no letterhead on purpose — still not a blank page.
    $ws->update([
        'sheet_image_path'      => null,
        'prescription_settings' => ['sheet_mode' => 'margins'],
    ]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeTrue();

    // "background" with no image cannot print anything: back to unconfigured.
    $ws->update(['prescription_settings' => ['sheet_mode' => 'background']]);

    expect(stepDone(
        $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json(),
        'letterhead',
    ))->toBeFalse();
});

it('tells the client whether documents would print on a letterhead', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    expect($this->getJson('/api/v1/settings', onboardingHeaders($ws->id))->assertOk()->json('data.has_letterhead'))
        ->toBeFalse();

    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);

    expect($this->getJson('/api/v1/settings', onboardingHeaders($ws->id))->assertOk()->json('data.has_letterhead'))
        ->toBeTrue();
});

it('gives a cashier a money checklist with no clinical steps', function () {
    $scenario = makeClinicScenario();
    $cashier  = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    app(ClinicService::class)->attachMember($scenario['clinic'], $cashier, 'billing');

    Sanctum::actingAs($cashier);
    $payload = $this->getJson('/api/v1/onboarding', onboardingHeaders($scenario['clinic']->id))
        ->assertOk()
        ->json();

    expect(stepKeys($payload))->toBe(['first_service', 'first_invoice']);
    expect(stepKeys($payload))->not->toContain('first_consultation');
    expect(stepKeys($payload))->not->toContain('first_prescription');
    // Not the owner, so no letterhead step either.
    expect(stepKeys($payload))->not->toContain('letterhead');
});

it('gives the receptionist patients and agenda but never clinical steps', function () {
    $scenario = makeClinicScenario();

    Sanctum::actingAs($scenario['recUser']);
    $payload = $this->getJson('/api/v1/onboarding', onboardingHeaders($scenario['clinic']->id))
        ->assertOk()
        ->json();

    expect(stepKeys($payload))->toContain('first_patient', 'first_appointment');
    expect(stepKeys($payload))->not->toContain('first_consultation');
    expect(stepKeys($payload))->not->toContain('first_prescription');
});

it('remembers a dismissal for the account, across workspaces', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/onboarding/dismiss', [], onboardingHeaders($ws->id))
        ->assertOk()
        ->assertJson(['dismissed' => true]);

    expect($this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->json('dismissed'))
        ->toBeTrue();
    expect($user->fresh()->onboarding_dismissed_at)->not->toBeNull();
});

it('requires authentication', function () {
    [, , $ws] = makeDoctorWithWorkspace();

    $this->getJson('/api/v1/onboarding', onboardingHeaders($ws->id))->assertUnauthorized();
});
