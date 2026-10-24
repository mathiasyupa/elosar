<?php

use App\Exceptions\ClinicalSeatLimitException;
use App\Models\Doctor;
use App\Models\User;
use App\Models\WorkspaceEntitlement;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

/**
 * Commercial rule under test: the clinic plan includes two attending doctors
 * (USD 21) and each one beyond that is billed (USD 7). Administrative roles —
 * reception, nursing, billing, administration — never consume a seat.
 */
function clinicWithOwner(): array
{
    [$owner] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Cupos');

    return [$clinic, $owner];
}

it('lets a clinic fill the two doctors its plan includes', function () {
    [$clinic] = clinicWithOwner();
    [$second] = makeDoctorWithWorkspace();

    app(ClinicService::class)->attachDoctor($clinic, $second, 'doctor');

    expect($clinic->fresh()->activeDoctors()->count())->toBe(2);
});

it('refuses the third attending doctor until the seat limit is raised', function () {
    [$clinic] = clinicWithOwner();
    [$second] = makeDoctorWithWorkspace();
    [$third] = makeDoctorWithWorkspace();

    $clinics = app(ClinicService::class);
    $clinics->attachDoctor($clinic, $second, 'doctor');

    expect(fn () => $clinics->attachDoctor($clinic->fresh(), $third, 'doctor'))
        ->toThrow(ClinicalSeatLimitException::class);

    // Nothing half-written: the rejected doctor never became a member.
    expect($clinic->fresh()->activeDoctors()->count())->toBe(2);
});

it('admits the third doctor once an operator widens the seat limit', function () {
    [$clinic] = clinicWithOwner();
    [$second] = makeDoctorWithWorkspace();
    [$third] = makeDoctorWithWorkspace();

    $clinics = app(ClinicService::class);
    $clinics->attachDoctor($clinic, $second, 'doctor');

    $clinic->entitlement()->update(['clinical_seat_limit' => 3]);

    $clinics->attachDoctor($clinic->fresh(), $third, 'doctor');

    expect($clinic->fresh()->activeDoctors()->count())->toBe(3);
});

it('never charges a seat to reception, nursing, billing or administration', function () {
    [$clinic] = clinicWithOwner();
    [$second] = makeDoctorWithWorkspace();

    $clinics = app(ClinicService::class);
    $clinics->attachDoctor($clinic, $second, 'doctor');

    // The clinic is already at its two included doctors, yet the whole
    // administrative team still joins.
    foreach (['receptionist', 'nurse', 'billing', 'admin'] as $role) {
        $staff = makeStaff(ucfirst($role).' Demo', $role.'_'.uniqid().'@clinica.test');
        $clinics->attachMember($clinic->fresh(), $staff, $role);
    }

    expect($clinic->fresh()->activeDoctors()->count())->toBe(2)
        ->and($clinic->fresh()->members()->where('status', 'active')->count())->toBe(6);
});

it('counts the seats a clinic pays extra for', function () {
    [$clinic] = clinicWithOwner();
    $clinic->entitlement()->update(['clinical_seat_limit' => 2]);

    $clinics = app(ClinicService::class);
    [$second] = makeDoctorWithWorkspace();
    $clinics->attachDoctor($clinic, $second, 'doctor');

    $entitlement = $clinic->fresh()->entitlement;

    expect($entitlement->includedClinicalSeats())->toBe(2)
        ->and($entitlement->additionalClinicalSeats())->toBe(0);

    // An operator widens the plan and a third doctor joins: one billable extra.
    $clinic->entitlement()->update(['clinical_seat_limit' => 2]);
    $user = User::factory()->create(['account_type' => 'doctor']);
    $extra = Doctor::factory()->withUser($user)->create();

    $clinic->fresh()->members()->create([
        'user_id' => $user->id,
        'doctor_id' => $extra->id,
        'role' => 'doctor',
        'roles' => ['doctor'],
        'status' => 'active',
    ]);

    expect($clinic->fresh()->entitlement->additionalClinicalSeats())->toBe(1);
});

it('keeps the plan code and seats a clinic starts with', function () {
    [$clinic] = clinicWithOwner();
    $entitlement = $clinic->entitlement;

    expect($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC)
        ->and($entitlement->includedClinicalSeats())->toBe(WorkspaceEntitlement::DEFAULT_CLINIC_SEATS);
});

it('refuses a doctor invitation when the clinic has no seat, before anyone fills a form', function () {
    $scenario = makeClinicScenario();
    Sanctum::actingAs($scenario['ownerUser']);

    // The scenario clinic already runs the two doctors its plan includes.
    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'sin-cupo@clinica.test',
        'role' => 'doctor',
    ], ['X-Workspace-Id' => (string) $scenario['clinic']->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'clinical_seat_limit_reached');

    // Nothing was issued: the invited doctor never receives a link that would
    // waste their time.
    $this->assertDatabaseMissing('clinic_invites', ['email' => 'sin-cupo@clinica.test']);
});

it('still invites reception, nursing, billing and administration with the clinic full', function () {
    $scenario = makeClinicScenario();
    Sanctum::actingAs($scenario['ownerUser']);
    $headers = ['X-Workspace-Id' => (string) $scenario['clinic']->id];

    foreach (['receptionist', 'nurse', 'billing', 'admin'] as $role) {
        $this->postJson('/api/v1/clinic/invite', [
            'email' => $role.'-libre@clinica.test',
            'role' => $role,
        ], $headers)->assertStatus(201);
    }
});

it('invites a doctor again once the seat limit is widened', function () {
    $scenario = makeClinicScenario();
    $scenario['clinic']->entitlement()->update(['clinical_seat_limit' => 3]);
    Sanctum::actingAs($scenario['ownerUser']);

    $this->postJson('/api/v1/clinic/invite', [
        'email' => 'con-cupo@clinica.test',
        'role' => 'doctor',
    ], ['X-Workspace-Id' => (string) $scenario['clinic']->id])
        ->assertStatus(201);
});
