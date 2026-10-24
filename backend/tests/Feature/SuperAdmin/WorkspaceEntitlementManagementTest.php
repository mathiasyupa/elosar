<?php

use App\Filament\SuperAdmin\Resources\WorkspaceResource\Pages\ListWorkspaces;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use App\Services\ClinicService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('activates a legacy workspace through an audited operator transition', function () {
    $operator = entitlementOperator();
    $workspace = Workspace::factory()->clinic()->create();

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('activate_entitlement', $workspace, data: [
            'operator_note' => 'Legacy clinic reviewed and approved for continued access.',
        ])
        ->assertHasNoTableActionErrors();

    $entitlement = $workspace->fresh('entitlement')->entitlement;

    expect($entitlement)->not->toBeNull()
        ->and($entitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($entitlement->source)->toBe(WorkspaceEntitlement::SOURCE_LEGACY_REVIEW);

    $event = WorkspaceEntitlementEvent::query()
        ->where('workspace_entitlement_id', $entitlement->id)
        ->sole();

    expect($event->action)->toBe('legacy_entitlement_provisioned')
        ->and($event->actor_user_id)->toBe($operator->id)
        ->and($event->after)->toMatchArray([
            'state' => WorkspaceEntitlement::STATE_ACTIVE,
            'operator_note' => 'Legacy clinic reviewed and approved for continued access.',
        ]);
});

it('extends a workspace trial through the superadmin panel with an audit note', function () {
    $operator = entitlementOperator();
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->readOnly()->create([
        'workspace_id' => $workspace->id,
    ]);

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('extend_trial', $workspace, data: [
            'trial_ends_at' => '2026-09-17',
            'operator_note' => 'Clinic approved for a two-week pilot extension.',
        ])
        ->assertHasNoTableActionErrors();

    $extended = $entitlement->fresh();

    expect($extended->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($extended->trial_ends_at->setTimezone('America/Guayaquil')->toDateString())->toBe('2026-09-17');

    $event = WorkspaceEntitlementEvent::query()
        ->where('workspace_entitlement_id', $extended->id)
        ->sole();

    expect($event->action)->toBe('operator_extend_trial')
        ->and($event->actor_user_id)->toBe($operator->id)
        ->and($event->after)->toMatchArray([
            'state' => WorkspaceEntitlement::STATE_TRIAL,
            'operator_note' => 'Clinic approved for a two-week pilot extension.',
        ]);
});

it('ends an extended trial at the end of the selected Ecuadorian day', function () {
    $operator = entitlementOperator();
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->trial()->create([
        'workspace_id' => $workspace->id,
        'plan_code' => WorkspaceEntitlement::PLAN_CLINIC,
    ]);
    $day = now('America/Guayaquil')->addDays(12)->toDateString();

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('extend_trial', $workspace, data: [
            'trial_ends_at' => $day,
            'operator_note' => 'Extended through the selected Ecuadorian day.',
        ])
        ->assertHasNoTableActionErrors();

    expect($entitlement->fresh()->trial_ends_at->setTimezone('America/Guayaquil')->format('Y-m-d H:i:s'))
        ->toBe($day.' 23:59:59');
});

it('widens the doctor seats a clinic pays for, with an audit note', function () {
    $operator = entitlementOperator();
    $workspace = Workspace::factory()->clinic()->create();
    $entitlement = WorkspaceEntitlement::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'plan_code' => WorkspaceEntitlement::PLAN_CLINIC,
        'clinical_seat_limit' => 2,
    ]);

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('set_seat_limit', $workspace, data: [
            'clinical_seat_limit' => 5,
            'operator_note' => 'Clinic pays for three additional doctors.',
        ])
        ->assertHasNoTableActionErrors();

    expect($entitlement->fresh()->includedClinicalSeats())->toBe(5);

    $event = WorkspaceEntitlementEvent::query()
        ->where('workspace_entitlement_id', $entitlement->id)
        ->where('action', 'operator_set_seat_limit')
        ->sole();

    expect($event->actor_user_id)->toBe($operator->id)
        ->and($event->after)->toMatchArray([
            'clinical_seat_limit' => 5,
            'operator_note' => 'Clinic pays for three additional doctors.',
        ]);
});

it('reopens an account trial so a spent trial has a way out', function () {
    $operator = entitlementOperator();

    // A doctor whose trial is over and whose included clinic is already used:
    // before this action there was no way to let them work again.
    [$doctor, $owner, $workspace] = makeDoctorWithWorkspace();
    $owner->accountTrialGrant()->update([
        'ends_at' => now()->subDay(),
        'first_clinic_claimed_at' => now()->subDays(5),
    ]);

    Livewire::actingAs($operator)
        ->test(ListWorkspaces::class)
        ->callTableAction('issue_trial_grant', $workspace, data: [
            'ends_at' => now()->addDays(15)->toDateString(),
            'operator_note' => 'Piloto extendido tras conversación comercial.',
        ])
        ->assertHasNoTableActionErrors();

    $grant = $owner->fresh()->accountTrialGrant;

    expect($grant->ends_at->isFuture())->toBeTrue()
        ->and($grant->first_clinic_claimed_at)->toBeNull();

    // The account can create its clinic again.
    expect(fn () => app(ClinicService::class)->createClinic($doctor->fresh(), 'Clínica Reabierta'))
        ->not->toThrow(DomainException::class);

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $workspace->id,
        'action' => 'operator_issued_account_grant',
        'actor_user_id' => $operator->id,
    ]);
});
