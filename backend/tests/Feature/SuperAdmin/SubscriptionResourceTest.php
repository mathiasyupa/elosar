<?php

use App\Filament\SuperAdmin\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\SubscriptionPayment;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('lists every subscription with its workspace', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->assertCanSeeTableRecords(WorkspaceEntitlement::query()->get())
        ->assertSee($workspace->name);
});

it('surfaces only what expires within the week, not what already lapsed', function () {
    $operator = entitlementOperator();

    [, , $soon] = makeDoctorWithWorkspace();
    [, , $later] = makeDoctorWithWorkspace();
    [, , $lapsed] = makeDoctorWithWorkspace();

    $soon->entitlement()->update(['trial_ends_at' => now()->addDays(3)]);
    $later->entitlement()->update(['trial_ends_at' => now()->addDays(20)]);
    $lapsed->entitlement()->update(['trial_ends_at' => now()->subDay()]);

    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->filterTable('expiring_soon')
        ->assertCanSeeTableRecords([$soon->fresh()->entitlement])
        // Already expired is a different conversation and a different list.
        ->assertCanNotSeeTableRecords([$later->fresh()->entitlement, $lapsed->fresh()->entitlement]);
});

it('flags as overdue only accounts that were paying and stopped', function () {
    $operator = entitlementOperator();

    [, , $neverPaid] = makeDoctorWithWorkspace();
    [, , $lapsed] = makeDoctorWithWorkspace();
    [, , $current] = makeDoctorWithWorkspace();

    $service = app(WorkspaceEntitlementService::class);

    $service->recordPayment($lapsed, [
        'amount' => '15.00',
        'method' => SubscriptionPayment::METHOD_TRANSFER,
        'paid_at' => today()->subMonths(2),
        'period_start' => today()->subMonths(2),
        'period_end' => today()->subDay(),
    ], null, extendAccess: false);

    $service->recordPayment($current, [
        'amount' => '15.00',
        'method' => SubscriptionPayment::METHOD_TRANSFER,
        'paid_at' => today(),
        'period_start' => today(),
        'period_end' => today()->addMonth(),
    ], null, extendAccess: false);

    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$lapsed->fresh()->entitlement])
        ->assertCanNotSeeTableRecords([$neverPaid->fresh()->entitlement, $current->fresh()->entitlement]);
});

it('records a payment from the list and extends access in one audited step', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();
    $periodEnd = today()->addMonth();

    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->callTableAction('record_payment', $workspace->entitlement, data: [
            'amount' => '21.00',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => today()->toDateString(),
            'period_start' => today()->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'reference' => 'TRF-9001',
            'operator_note' => 'Transferencia confirmada por el banco.',
            'extend_access' => true,
        ])
        ->assertHasNoTableActionErrors();

    $entitlement = $workspace->fresh()->entitlement;

    expect($entitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($workspace->fresh()->paidUntil()->toDateString())->toBe($periodEnd->toDateString());

    $this->assertDatabaseHas('subscription_payments', [
        'workspace_id' => $workspace->id,
        'amount' => '21.00',
        'reference' => 'TRF-9001',
        'recorded_by_user_id' => $operator->id,
    ]);
});

it('can record money without touching access when the operator says so', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->callTableAction('record_payment', $workspace->entitlement, data: [
            'amount' => '15.00',
            'method' => SubscriptionPayment::METHOD_CASH,
            'paid_at' => today()->toDateString(),
            'period_start' => today()->toDateString(),
            'period_end' => today()->addMonth()->toDateString(),
            'operator_note' => 'Pago parcial, acceso pendiente de revisión.',
            'extend_access' => false,
        ])
        ->assertHasNoTableActionErrors();

    // Money in, access untouched: still the trial it was.
    expect($workspace->fresh()->entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($workspace->fresh()->paidUntil())->not->toBeNull();
});

it('refuses to record a payment without saying why', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    // The note is optional on a payment (the amount and period speak), but the
    // period is not: without it nothing can tell who is up to date.
    Livewire::actingAs($operator)
        ->test(ListSubscriptions::class)
        ->callTableAction('record_payment', $workspace->entitlement, data: [
            'amount' => '21.00',
            'method' => SubscriptionPayment::METHOD_TRANSFER,
            'paid_at' => today()->toDateString(),
            'period_start' => today()->toDateString(),
            'period_end' => null,
        ])
        ->assertHasTableActionErrors(['period_end']);
});
