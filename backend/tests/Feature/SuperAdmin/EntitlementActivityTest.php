<?php

use App\Filament\SuperAdmin\Resources\EntitlementEventResource;
use App\Filament\SuperAdmin\Resources\EntitlementEventResource\Pages\ListEntitlementEvents;
use App\Models\WorkspaceEntitlementEvent;
use App\Services\WorkspaceEntitlementService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('superadmin'));
});

it('makes the reason behind every operator action readable', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    app(WorkspaceEntitlementService::class)->setReadOnly(
        $workspace->entitlement,
        $operator,
        'Impago tras tres recordatorios.',
    );

    // The note is the whole point: an audit trail that only accepts writes is
    // not an audit trail.
    Livewire::actingAs($operator)
        ->test(ListEntitlementEvents::class)
        ->assertCanSeeTableRecords(WorkspaceEntitlementEvent::query()->get())
        ->assertSee('Impago tras tres recordatorios.')
        ->assertSee('Solo lectura');
});

it('filters the history by what was done', function () {
    $operator = entitlementOperator();
    [, , $blocked] = makeDoctorWithWorkspace();
    [, , $extended] = makeDoctorWithWorkspace();

    $service = app(WorkspaceEntitlementService::class);
    $service->setReadOnly($blocked->entitlement, $operator, 'Bloqueo comercial.');
    $service->extendTrial($extended->entitlement, now()->addDays(10), $operator, 'Piloto ampliado.');

    Livewire::actingAs($operator)
        ->test(ListEntitlementEvents::class)
        ->filterTable('action', 'operator_set_read_only')
        ->assertSee('Bloqueo comercial.')
        ->assertDontSee('Piloto ampliado.');
});

it('never offers to create, edit or delete an append-only record', function () {
    [, , $workspace] = makeDoctorWithWorkspace();
    $event = WorkspaceEntitlementEvent::query()
        ->where('workspace_id', $workspace->id)
        ->firstOrFail();

    // PostgreSQL triggers reject UPDATE, DELETE and TRUNCATE on this table.
    // The panel reflects that guarantee rather than offering buttons the
    // database will refuse.
    expect(EntitlementEventResource::canCreate())->toBeFalse()
        ->and(EntitlementEventResource::canEdit($event))->toBeFalse()
        ->and(EntitlementEventResource::canDelete($event))->toBeFalse();
});

it('attributes an automatic expiry to the system, not to a person', function () {
    $operator = entitlementOperator();
    [, , $workspace] = makeDoctorWithWorkspace();

    $workspace->entitlement()->update(['trial_ends_at' => now()->subDay()]);
    app(WorkspaceEntitlementService::class)->expireTrial($workspace->fresh()->entitlement);

    Livewire::actingAs($operator)
        ->test(ListEntitlementEvents::class)
        ->assertSee('Sistema');

    $this->assertDatabaseHas('workspace_entitlement_events', [
        'workspace_id' => $workspace->id,
        'action' => 'trial_expired',
        'actor_user_id' => null,
    ]);
});
