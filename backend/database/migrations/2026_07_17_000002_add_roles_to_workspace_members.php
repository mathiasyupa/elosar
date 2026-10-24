<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-role membership. Replaces the single `role` + boolean overlays
     * (`is_admin`, `can_triage`) with a `roles` array that SUMS permissions:
     * a "doctor who administers" becomes [doctor, admin]; a polyvalent
     * receptionist-triage becomes [receptionist, nurse]. This removes the
     * role/overlay duality (WorkspacePermissionService reads `roles` now).
     *
     * The legacy `role`, `is_admin`, `can_triage` columns are kept (dropped in a
     * later migration once stabilized). `roles` is the source of truth.
     */
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->jsonb('roles')->default('[]')->after('role');
        });

        // Backfill from the legacy columns: base role + admin overlay (→ 'admin')
        // + triage overlay (→ 'nurse', which grants vitals.create).
        DB::table('workspace_members')->select('id', 'role', 'is_admin', 'can_triage')->orderBy('id')->get()
            ->each(function ($m) {
                $roles = array_values(array_unique(array_filter([
                    $m->role,
                    $m->is_admin ? 'admin' : null,
                    $m->can_triage ? 'nurse' : null,
                ])));

                DB::table('workspace_members')->where('id', $m->id)->update([
                    'roles' => json_encode($roles ?: [$m->role]),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
