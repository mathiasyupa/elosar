<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\TrialCampaignService;
use App\Services\WorkspaceEntitlementService;
use Illuminate\Console\Command;

/**
 * Brings accounts that predate the entitlement rollout into the new model.
 *
 * Two separate gaps are closed here, and both matter. A workspace without an
 * entitlement resolves to read-only, so every existing workspace needs one
 * before write enforcement can be switched on. And an account without a trial
 * grant cannot create a clinic at all — ClinicService rejects it — so doctors
 * who could open a clinic yesterday would silently lose that ability.
 *
 * Legacy workspaces are provisioned as `legacy_review`: full access, with the
 * seats they already use, until a platform admin deliberately changes them.
 * Nothing here infers payment, a price or a commercial history.
 */
class BackfillWorkspaceEntitlements extends Command
{
    protected $signature = 'elosar:backfill-workspace-entitlements {--dry-run : Report what would change without writing}';

    protected $description = 'Give pre-existing workspaces an entitlement and pre-existing accounts a trial grant';

    public function handle(
        WorkspaceEntitlementService $entitlements,
        TrialCampaignService $campaigns,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        $workspaces = Workspace::query()
            ->whereDoesntHave('entitlement')
            ->with('entitlement')
            ->get();

        $this->info("Workspaces without an entitlement: {$workspaces->count()}");

        if (! $dryRun) {
            foreach ($workspaces as $workspace) {
                $entitlements->provisionLegacyEntitlement($workspace);
            }
        }

        $owners = User::query()
            ->whereDoesntHave('accountTrialGrant')
            ->whereHas('ownedWorkspaces')
            ->with('ownedWorkspaces')
            ->get();

        $this->info("Accounts without a trial grant: {$owners->count()}");

        $granted = 0;

        if (! $dryRun) {
            foreach ($owners as $owner) {
                $mode = $campaigns->modeFor($owner);

                if ($mode === null) {
                    continue;
                }

                $grant = $campaigns->ensureGrant($owner, $mode);

                if (! $grant) {
                    continue;
                }

                $granted++;

                // An account that already runs a clinic has, in every sense
                // that matters, already used its included clinic. Mark it so
                // the backfill does not hand out a second free one.
                if ($grant->first_clinic_claimed_at === null && $this->alreadyOwnsClinic($owner)) {
                    $grant->update(['first_clinic_claimed_at' => now()]);
                }
            }
        }

        $this->info($dryRun
            ? 'Dry run: nothing was written.'
            : "Entitlements created: {$workspaces->count()} · grants issued: {$granted}");

        return self::SUCCESS;
    }

    private function alreadyOwnsClinic(User $owner): bool
    {
        return $owner->ownedWorkspaces
            ->contains(fn (Workspace $workspace): bool => $workspace->isClinic());
    }
}
