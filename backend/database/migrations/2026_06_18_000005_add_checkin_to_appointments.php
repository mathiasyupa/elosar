<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Front-desk check-in timestamp for the clinic day-queue pipeline. The
     * appointment `status` column is a free string (no DB enum), so the extended
     * values — scheduled → checked_in → vitals_done → with_doctor → completed,
     * plus cancelled / no_show — are enforced in code, not schema.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('checked_in_at');
        });
    }
};
