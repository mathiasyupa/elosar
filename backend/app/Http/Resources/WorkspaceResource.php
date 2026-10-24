<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_clinic' => $this->isClinic(),
            'entitlement' => $this->entitlementPayload(),
            'logo_path' => $this->logo_path,
            'sri_enabled' => (bool) $this->sri_enabled,

            // Role-aware fields, decorated per-user by WorkspacePermissionService
            // ::decorate() before serialization (transient, never persisted).
            // `roles` is the full set the user holds; `membership_role` is the
            // representative one for badges.
            'membership_role' => $this->membership_role ?? null,
            'roles' => $this->roles ?? [],
            'is_owner' => (bool) ($this->is_owner ?? false),
            'permissions' => $this->permissions ?? [],
        ];
    }

    /**
     * The workspace's commercial state, as the app needs it to decide what to
     * show and what to disable. `can_write` is the one the UI should gate on:
     * it already accounts for a trial whose end date has passed, which the
     * stored `state` alone does not.
     *
     * @return array<string, mixed>|null
     */
    private function entitlementPayload(): ?array
    {
        $entitlement = $this->entitlement;

        if ($entitlement === null) {
            return null;
        }

        $entitlements = app(WorkspaceEntitlementService::class);
        $access = $entitlements->effectiveAccess($this->resource);

        return [
            'state' => $entitlement->state,
            'effective_access' => $access,
            'can_write' => $access === WorkspaceEntitlementService::ACCESS_FULL,
            'plan_code' => $entitlement->plan_code,
            'trial_ends_at' => $entitlement->trial_ends_at?->toIso8601String(),
            'access_ends_at' => $entitlement->access_ends_at?->toIso8601String(),
            'clinical_seats' => $entitlements->seatSummary($this->resource),
            'bundle_eligible' => $entitlements->bundleEligible($this->resource),
            'is_legacy_review' => $entitlement->source === WorkspaceEntitlement::SOURCE_LEGACY_REVIEW,
        ];
    }
}
