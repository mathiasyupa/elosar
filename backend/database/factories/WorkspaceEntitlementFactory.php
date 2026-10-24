<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceEntitlement>
 */
class WorkspaceEntitlementFactory extends Factory
{
    protected $model = WorkspaceEntitlement::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'plan_code' => WorkspaceEntitlement::PLAN_PERSONAL,
            'state' => WorkspaceEntitlement::STATE_ACTIVE,
            'source' => WorkspaceEntitlement::SOURCE_LEGACY_REVIEW,
            'trial_ends_at' => null,
            'access_ends_at' => null,
            'clinical_seat_limit' => null,
        ];
    }

    public function trial(): static
    {
        return $this->state([
            'state' => WorkspaceEntitlement::STATE_TRIAL,
            'source' => WorkspaceEntitlement::SOURCE_SIGNUP,
            'trial_ends_at' => now()->addDays(30),
            'access_ends_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state([
            'state' => WorkspaceEntitlement::STATE_ACTIVE,
            'trial_ends_at' => null,
            'access_ends_at' => null,
        ]);
    }

    public function readOnly(): static
    {
        return $this->state([
            'state' => WorkspaceEntitlement::STATE_READ_ONLY,
            'trial_ends_at' => null,
            'access_ends_at' => now()->addDays(30),
        ]);
    }
}
