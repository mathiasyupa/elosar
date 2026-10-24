<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the legacy `subscription_status` column.
 *
 * It predates entitlements and, since they shipped, decided nothing: access is
 * `workspace_entitlements.state`, and a platform block is `suspended_at`. What
 * it did keep doing was inviting a wrong answer — a column named
 * "subscription status" sitting next to the real one will eventually be read by
 * somebody, and it was never maintained after the rollout.
 *
 * Nothing writes or reads it anymore: the API resources, both Filament panels,
 * the factory and the services were cleared first, and `medico-web` reads the
 * `entitlement` object.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('subscription_status', 16)->default('trial');
        });

        // Best-effort reconstruction from the authoritative source, so a
        // rollback lands on something coherent rather than "trial" for
        // everyone. The old `expired`/`cancelled` values have no equivalent:
        // read-only is what replaced both.
        DB::statement(<<<'SQL'
            UPDATE workspaces w
            SET subscription_status = CASE e.state
                WHEN 'active' THEN 'active'
                WHEN 'trial' THEN 'trial'
                ELSE 'expired'
            END
            FROM workspace_entitlements e
            WHERE e.workspace_id = w.id
        SQL);
    }
};
