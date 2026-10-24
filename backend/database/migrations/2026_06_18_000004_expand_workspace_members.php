<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promotes workspace_members to the AUTHORITATIVE membership table:
     *  - keyed by user_id (works for doctors AND staff)
     *  - doctor_id becomes nullable (staff have no Doctor record)
     *  - optional staff_profile_id link
     *  - role enum widened to the full clinic role set
     *
     * Access control, role and ownership resolution all read from this table.
     * clinic_doctor remains as a convenience pivot kept in sync by ClinicService.
     */
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                  ->constrained('users')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->after('doctor_id')
                  ->constrained('staff_profiles')->nullOnDelete();
        });

        // Backfill user_id from the existing doctor membership.
        DB::statement(
            'UPDATE workspace_members wm SET user_id = d.user_id
             FROM doctors d
             WHERE wm.doctor_id = d.id AND d.user_id IS NOT NULL'
        );

        // doctor_id is no longer required (staff members have none).
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->change();
        });

        // Widen the role enum (was 'doctor'|'staff'). Postgres models enums as a
        // CHECK constraint; drop it, migrate legacy 'staff' → 'nurse', re-add.
        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT IF EXISTS workspace_members_role_check');
        DB::table('workspace_members')->where('role', 'staff')->update(['role' => 'nurse']);
        DB::statement(
            "ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_role_check
             CHECK (role IN ('doctor', 'nurse', 'receptionist', 'billing', 'admin'))"
        );

        // One membership per user per workspace.
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->unique(['user_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'workspace_id']);
            $table->dropConstrainedForeignId('staff_profile_id');
            $table->dropConstrainedForeignId('user_id');
        });

        DB::statement('ALTER TABLE workspace_members DROP CONSTRAINT IF EXISTS workspace_members_role_check');
        DB::statement(
            "ALTER TABLE workspace_members ADD CONSTRAINT workspace_members_role_check
             CHECK (role IN ('doctor', 'staff'))"
        );
    }
};
