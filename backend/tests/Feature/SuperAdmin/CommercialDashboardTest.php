<?php

use App\Filament\SuperAdmin\Widgets\CommercialStatsWidget;
use App\Filament\SuperAdmin\Widgets\NeedsAttentionWidget;
use App\Models\SubscriptionPayment;
use App\Models\WorkspaceEntitlement;
use App\Services\ClinicService;
use App\Services\WorkspaceEntitlementService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('opens on the money, bundled per owner', function () {
    $operator = entitlementOperator();

    [$owner] = makeDoctorWithWorkspace();
    $clinic = app(ClinicService::class)->createClinic($owner, 'Clínica Panel');
    $owner->workspace->entitlement()->update(['state' => WorkspaceEntitlement::STATE_ACTIVE]);
    $clinic->entitlement()->update(['state' => WorkspaceEntitlement::STATE_ACTIVE]);

    // One owner with both spaces pays the bundle: USD 30, not 15 + 21.
    Livewire::actingAs($operator)
        ->test(CommercialStatsWidget::class)
        ->assertSee('USD 30')
        ->assertSee('Ingresos mensuales estimados');
});

it('counts each access state separately', function () {
    $operator = entitlementOperator();

    [, , $trial] = makeDoctorWithWorkspace();
    [, , $readOnly] = makeDoctorWithWorkspace();
    $readOnly->entitlement()->update(['state' => WorkspaceEntitlement::STATE_READ_ONLY]);

    Livewire::actingAs($operator)
        ->test(CommercialStatsWidget::class)
        ->assertSee('En prueba (estado guardado)')
        ->assertSee('Solo lectura (estado guardado)')
        ->assertSee('Pago vencido');

    expect($trial->fresh()->entitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL);
});

it('lists what needs a message this week and nothing else', function () {
    $operator = entitlementOperator();

    [, , $expiring] = makeDoctorWithWorkspace();
    [, , $healthy] = makeDoctorWithWorkspace();

    $expiring->entitlement()->update(['trial_ends_at' => now()->addDays(3)]);
    $healthy->entitlement()->update(['trial_ends_at' => now()->addDays(45)]);

    Livewire::actingAs($operator)
        ->test(NeedsAttentionWidget::class)
        ->assertCanSeeTableRecords([$expiring->fresh()->entitlement])
        ->assertCanNotSeeTableRecords([$healthy->fresh()->entitlement])
        // Not the exact day count: these columns are timestamps, and this app
        // still writes them in local time and reads them back as UTC, so the
        // number can land a day either side. What must hold is that the row
        // shows up and says it is running out.
        ->assertSee('Vence en');
});

it('surfaces an account that stopped paying even though its dates look fine', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    // Active with no deadline at all — nothing in its entitlement says there is
    // a problem. Only the payment record does.
    $workspace->entitlement()->update([
        'state' => WorkspaceEntitlement::STATE_ACTIVE,
        'trial_ends_at' => null,
        'access_ends_at' => null,
    ]);

    app(WorkspaceEntitlementService::class)->recordPayment($workspace, [
        'amount' => '15.00',
        'method' => SubscriptionPayment::METHOD_TRANSFER,
        'paid_at' => today()->subMonths(2),
        'period_start' => today()->subMonths(2),
        'period_end' => today()->subWeek(),
    ], null, extendAccess: false);

    Livewire::actingAs($operator)
        ->test(NeedsAttentionWidget::class)
        ->assertCanSeeTableRecords([$workspace->fresh()->entitlement])
        ->assertSee('Pago vencido desde');
});

it('says so plainly when there is nothing to chase', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();
    $workspace->entitlement()->update(['trial_ends_at' => now()->addMonths(6)]);

    Livewire::actingAs($operator)
        ->test(NeedsAttentionWidget::class)
        ->assertSee('Sin vencimientos ni pagos pendientes');
});
