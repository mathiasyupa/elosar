<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves clinical attachments (medical_attachments) from APPOINTMENTS to
     * MEDICAL RECORDS.
     *
     * Rationale: evidence (X-rays, PDFs, lab results) belongs to the medical
     * consultation, not to scheduling. It makes far more sense hanging off the
     * patient's medical record, which is where the case is documented.
     */
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table) {
            // Remove the previous link to appointments
            $table->dropForeign(['appointment_id']);
            $table->dropColumn('appointment_id');

            // New link to the medical record
            $table->foreignId('medical_record_id')
                  ->after('id')
                  ->constrained('medical_records')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table) {
            $table->dropForeign(['medical_record_id']);
            $table->dropColumn('medical_record_id');

            $table->foreignId('appointment_id')
                  ->after('id')
                  ->constrained('appointments')
                  ->onDelete('cascade');
        });
    }
};
