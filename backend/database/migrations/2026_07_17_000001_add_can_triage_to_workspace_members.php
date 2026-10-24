<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Triage overlay. Grants `vitals.create` ON TOP of a member's base role, so
     * the front-desk auxiliary of a small clinic (reception + cash desk) can also
     * take the patient's vital signs — the polyvalent nurse-receptionist common
     * in Ecuadorian centres. Independent of role, is_admin and ownership.
     */
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->boolean('can_triage')->default(false)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropColumn('can_triage');
        });
    }
};
