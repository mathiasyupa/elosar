<?php

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementEvent;
use Database\Seeders\ClinicalDataSeeder;
use Database\Seeders\SpecialtySeeder;

it('provisions demo workspaces with audited entitlements', function () {
    $this->seed(SpecialtySeeder::class);
    $this->seed(ClinicalDataSeeder::class);

    $clinic = Workspace::query()
        ->where('name', ClinicalDataSeeder::CLINIC_NAME)
        ->sole();
    $clinicEntitlement = $clinic->entitlement()->sole();
    $personalEntitlement = WorkspaceEntitlement::query()
        ->whereHas('workspace', fn ($query) => $query
            ->where('type', 'personal')
            ->whereHas('ownerUser', fn ($owner) => $owner->where('email', 'test@example.com')))
        ->sole();

    // The demo clinic is written straight into the seeder, so it carries the
    // legacy-review entitlement an existing clinic gets. A doctor's consulting
    // room, on the other hand, comes from the real Doctor lifecycle and so
    // starts its signup trial exactly like a doctor registering today.
    expect($clinicEntitlement->state)->toBe(WorkspaceEntitlement::STATE_ACTIVE)
        ->and($clinicEntitlement->source)->toBe(WorkspaceEntitlement::SOURCE_LEGACY_REVIEW)
        ->and($personalEntitlement->state)->toBe(WorkspaceEntitlement::STATE_TRIAL)
        ->and($personalEntitlement->source)->toBe(WorkspaceEntitlement::SOURCE_SIGNUP)
        ->and($personalEntitlement->canWrite())->toBeTrue()
        // Sized to the team the demo actually runs: two doctors included in the
        // clinic plan and the rest billed as additional seats.
        ->and($clinicEntitlement->includedClinicalSeats())->toBe($clinic->activeDoctors()->count())
        ->and($clinicEntitlement->includedClinicalSeats())->toBeGreaterThan(WorkspaceEntitlement::DEFAULT_CLINIC_SEATS);

    expect(WorkspaceEntitlementEvent::query()
        ->where('workspace_id', $clinic->id)
        ->where('workspace_entitlement_id', $clinicEntitlement->id)
        ->where('action', 'legacy_entitlement_provisioned')
        ->exists())->toBeTrue();
});
