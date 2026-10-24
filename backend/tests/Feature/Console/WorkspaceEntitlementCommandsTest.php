<?php

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;

it('persists every expired trial once and audits the automatic transition', function () {
    $now = CarbonImmutable::parse('2026-08-03 00:05:00', 'America/Guayaquil');
    $this->travelTo($now);

    try {
        $workspace = Workspace::factory()->clinic()->create();
        $entitlement = WorkspaceEntitlement::factory()->trial()->create([
            'workspace_id' => $workspace->id,
            'trial_ends_at' => $now->subSecond(),
        ]);

        $this->artisan('elosar:expire-workspace-entitlements')
            ->expectsOutputToContain('Expired 1 workspace entitlement(s).')
            ->assertSuccessful();

        expect($entitlement->fresh()->state)->toBe(WorkspaceEntitlement::STATE_READ_ONLY)
            ->and(WorkspaceEntitlementEvent::query()
                ->where('workspace_entitlement_id', $entitlement->id)
                ->where('action', 'trial_expired')
                ->count())->toBe(1);

        $this->artisan('elosar:expire-workspace-entitlements')
            ->expectsOutputToContain('Expired 0 workspace entitlement(s).')
            ->assertSuccessful();

        expect(WorkspaceEntitlementEvent::query()
            ->where('workspace_entitlement_id', $entitlement->id)
            ->where('action', 'trial_expired')
            ->count())->toBe(1);
    } finally {
        $this->travelBack();
    }
});

it('schedules entitlement expiry every day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'elosar:expire-workspace-entitlements'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
