<?php

namespace App\Console\Commands;

use App\Models\WorkspaceEntitlement;
use App\Services\WorkspaceEntitlementService;
use Illuminate\Console\Command;

class ExpireWorkspaceEntitlements extends Command
{
    protected $signature = 'elosar:expire-workspace-entitlements';

    protected $description = 'Persist expired trials as read-only; request-time enforcement remains the safety net.';

    public function handle(WorkspaceEntitlementService $entitlements): int
    {
        $expired = 0;

        WorkspaceEntitlement::query()
            ->where('state', WorkspaceEntitlement::STATE_TRIAL)
            ->where('trial_ends_at', '<', now())
            ->eachById(function (WorkspaceEntitlement $entitlement) use ($entitlements, &$expired): void {
                if ($entitlements->expireTrial($entitlement)) {
                    $expired++;
                }
            });

        $this->info("Expired {$expired} workspace entitlement(s).");

        return self::SUCCESS;
    }
}
