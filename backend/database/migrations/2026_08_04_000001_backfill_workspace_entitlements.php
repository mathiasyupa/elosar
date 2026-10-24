<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Runs the entitlement backfill as part of the deploy.
 *
 * This is deliberately a migration and not a manual step. Write enforcement
 * reads the entitlement on every request and treats a missing one as
 * read-only, so there must be no window — not even a minute between
 * `migrate` and an operator remembering to run a command — in which live
 * workspaces exist without one.
 *
 * The command is idempotent, so re-running it (new environments, a replayed
 * deploy) is safe and this migration stays reversible in the only sense that
 * matters: rolling back the application code leaves the rows untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fresh databases run this migration before anyone exists. Skipping the
        // work keeps `migrate:fresh` fast and the output quiet.
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        Artisan::call('elosar:backfill-workspace-entitlements');
    }

    public function down(): void
    {
        // Entitlements and their audit events are never deleted on rollback:
        // the events table is append-only by design, and discarding a
        // workspace's commercial state would be worse than keeping it.
    }
};
