<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE workspace_entitlements DROP CONSTRAINT workspace_entitlements_plan_code_check');
        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_plan_code_check CHECK (plan_code IN ('personal', 'clinic', 'clinic_unlimited'))");
        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_unlimited_seats_check CHECK (plan_code <> 'clinic_unlimited' OR clinical_seat_limit IS NULL)");
    }

    public function down(): void
    {
        if (DB::table('workspace_entitlements')->where('plan_code', 'clinic_unlimited')->exists()) {
            throw new LogicException('Assign a standard plan to every unlimited clinic before rolling back.');
        }

        DB::statement('ALTER TABLE workspace_entitlements DROP CONSTRAINT workspace_entitlements_unlimited_seats_check');
        DB::statement('ALTER TABLE workspace_entitlements DROP CONSTRAINT workspace_entitlements_plan_code_check');
        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_plan_code_check CHECK (plan_code IN ('personal', 'clinic'))");
    }
};
