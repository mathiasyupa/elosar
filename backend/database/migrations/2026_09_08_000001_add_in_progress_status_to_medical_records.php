<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Adds 'in_progress' to the status CHECK: a consultation autosaved by the
     * doctor mid-visit (so the prescription/study-order PDFs can be generated
     * before the visit is actually finished), distinct from 'completed' so it
     * never trips the appointment-completion side effects — dashboard counts,
     * "por cobrar", the appointment leaving the queue — that a finished visit
     * does. See MedicalRecordController::finalizeVisit().
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE medical_records DROP CONSTRAINT IF EXISTS medical_records_status_check');

        DB::statement(
            "ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check
             CHECK (status IN ('draft', 'vitals_done', 'in_progress', 'completed'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE medical_records DROP CONSTRAINT IF EXISTS medical_records_status_check');

        DB::statement(
            "ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check
             CHECK (status IN ('draft', 'vitals_done', 'completed'))"
        );
    }
};
