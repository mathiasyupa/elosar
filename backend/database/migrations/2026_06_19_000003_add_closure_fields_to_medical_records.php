<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mandatory consultation closure (forcing function). The doctor may close a
     * visit with the minimum (motivo) and mark it completed; closing without a
     * diagnosis sets closed_with_override so we can report on clinical
     * completeness (MSP norm) without blocking the day's flow. completion_note is
     * an optional free-text reason for the override.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->boolean('closed_with_override')->default(false)->after('status');
            $table->text('completion_note')->nullable()->after('closed_with_override');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['closed_with_override', 'completion_note']);
        });
    }
};
