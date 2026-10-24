<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clinic-admin overlay. `admin` capabilities (manage billing, see reports +
     * team) are layered ON TOP of a member's base role via this flag — so a
     * doctor who also administers the clinic is role=doctor + is_admin=true,
     * keeping full clinical access while gaining operational admin powers.
     * Settings/SRI stay owner-only; admin is NOT ownership.
     */
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
