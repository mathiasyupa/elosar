<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds three new fields to the 'patients' table:
     *
     * 1. workspace_id        → which workspace owns this patient (data owner)
     * 2. civil_status        → civil status as ENUM (controlled, reportable values)
     * 3. personal_background → personal history as JSONB (allergies, surgeries,
     *                          medication, habits — flexible per specialty)
     *
     * Note: the Spanish enum values below are historical; a later migration
     * (2026_06_06_250001) translates them to English.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            // Workspace that owns the patient
            $table->foreignId('workspace_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('workspaces')
                  ->onDelete('set null');

            // Civil status: ENUM with fixed values validated by PostgreSQL
            $table->enum('civil_status', [
                'soltero',
                'casado',
                'union_libre',
                'divorciado',
                'viudo',
                'separado',
            ])->nullable()->after('gender');

            // Personal history: JSONB, flexible per medical specialty.
            // Example structure: {"allergies": ["penicillin"], "surgeries": ["appendectomy"],
            //                     "current_medication": [], "habits": {"tobacco": false}}
            // The GIN index enables fast lookups inside the JSON.
            $table->jsonb('personal_background')->nullable()->after('civil_status');
        });

        // GIN index for searches inside the JSONB field
        \DB::statement('CREATE INDEX idx_patients_personal_background ON patients USING GIN (personal_background)');
    }

    public function down(): void
    {
        \DB::statement('DROP INDEX IF EXISTS idx_patients_personal_background');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn(['workspace_id', 'civil_status', 'personal_background']);
        });
    }
};
