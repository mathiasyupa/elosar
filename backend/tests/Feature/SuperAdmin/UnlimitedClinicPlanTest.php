<?php

use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Services\ClinicService;
use App\Services\WorkspaceEntitlementService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('gives a clinic unlimited manual access without charging for doctors', function () {
    [$ownerDoctor, , $personal] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($ownerDoctor, fake()->company());
    $operator = entitlementOperator();
    $service = app(WorkspaceEntitlementService::class);

    $service->changePlan($clinic->entitlement, WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED, $operator, 'Manual unlimited access approved.');
    $clinic->refresh();

    for ($i = 0; $i < 4; $i++) {
        [$doctor] = makeDoctorWithWorkspace();
        app(ClinicService::class)->attachDoctor($clinic, $doctor);
    }

    $entitlement = $clinic->fresh('entitlement')->entitlement;
    $summary = $service->seatSummary($clinic->fresh('entitlement'));

    expect($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED)
        ->and($entitlement->clinical_seat_limit)->toBeNull()
        ->and($entitlement->includedClinicalSeats())->toBeNull()
        ->and($entitlement->isSeatLimitReached())->toBeFalse()
        ->and($entitlement->additionalClinicalSeats())->toBe(0)
        ->and($entitlement->monthlyPriceUsd())->toBe(0)
        ->and($summary)->toBe(['included' => null, 'active_doctors' => 5, 'additional' => 0])
        ->and($personal->fresh('entitlement')->entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_PERSONAL);

    $event = WorkspaceEntitlementEvent::query()->where('workspace_id', $clinic->id)
        ->where('action', 'operator_changed_plan')->sole();

    expect($event->actor_user_id)->toBe($operator->id)
        ->and($event->after['plan_code'])->toBe(WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED)
        ->and($event->after['operator_note'])->toBe('Manual unlimited access approved.');
});

it('restores enough seats when returning a clinic to the standard plan', function () {
    [$ownerDoctor] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($ownerDoctor, fake()->company());
    $operator = entitlementOperator();
    $service = app(WorkspaceEntitlementService::class);
    $service->changePlan($clinic->entitlement, WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED, $operator, 'Open access.');
    $clinic->refresh();

    for ($i = 0; $i < 3; $i++) {
        [$doctor] = makeDoctorWithWorkspace();
        app(ClinicService::class)->attachDoctor($clinic, $doctor);
    }

    $service->changePlan($clinic->fresh('entitlement')->entitlement, WorkspaceEntitlement::PLAN_CLINIC, $operator, 'Return to standard clinic.');
    $entitlement = $clinic->fresh('entitlement')->entitlement;

    expect($entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC)
        ->and($entitlement->clinical_seat_limit)->toBe(WorkspaceEntitlement::DEFAULT_CLINIC_SEATS)
        ->and($entitlement->isSeatLimitReached())->toBeTrue()
        ->and($entitlement->additionalClinicalSeats())->toBe(2)
        ->and($entitlement->monthlyPriceUsd())->toBe(35)
        ->and($clinic->members()->where('status', 'active')->whereNotNull('doctor_id')->count())->toBe(4);

    $event = WorkspaceEntitlementEvent::query()->where('workspace_id', $clinic->id)
        ->where('action', 'operator_changed_plan')->latest('id')->firstOrFail();
    expect($event->actor_user_id)->toBe($operator->id)
        ->and($event->before['plan_code'])->toBe(WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED)
        ->and($event->after['plan_code'])->toBe(WorkspaceEntitlement::PLAN_CLINIC)
        ->and($event->after['operator_note'])->toBe('Return to standard clinic.');
});

it('never changes a personal workspace into a clinic plan', function () {
    [, , $personal] = makeDoctorWithWorkspace();
    $service = app(WorkspaceEntitlementService::class);

    expect(fn () => $service->changePlan($personal->entitlement, WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED))
        ->toThrow(LogicException::class);
    expect($personal->fresh('entitlement')->entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_PERSONAL);
});

it('changes a clinic to unlimited from the superadmin action and records the actor', function () {
    [$doctor] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($doctor, fake()->company());
    $operator = entitlementOperator();

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('change_clinic_plan', $clinic, data: [
            'plan_code' => WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED,
            'operator_note' => 'Manual unlimited access for this clinic.',
        ])
        ->assertHasNoTableActionErrors();

    expect($clinic->fresh('entitlement')->entitlement->plan_code)->toBe(WorkspaceEntitlement::PLAN_CLINIC_UNLIMITED);
    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $clinic->id,
        'action' => 'operator_changed_plan',
        'actor_user_id' => $operator->id,
    ]);
});
