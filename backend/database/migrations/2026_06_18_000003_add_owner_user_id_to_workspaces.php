<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ownership keyed by user, not doctor. ANY account type can own a clinic
     * (e.g. a nurse who runs the business). owner_doctor_id is kept for backward
     * compatibility; new code prefers owner_user_id.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('owner_doctor_id')
                  ->constrained('users')->nullOnDelete();
        });

        // Backfill from the existing doctor-based ownership.
        DB::statement(
            'UPDATE workspaces w SET owner_user_id = d.user_id
             FROM doctors d
             WHERE w.owner_doctor_id = d.id AND d.user_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
