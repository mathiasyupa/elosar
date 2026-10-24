<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table) {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('id')
                ->constrained('workspaces')
                ->nullOnDelete();

            $table->foreignId('patient_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('patients')
                ->cascadeOnDelete();
        });

        // Populate workspace_id and patient_id for existing attachments via medical_records
        DB::statement("
            UPDATE medical_attachments ma
            SET workspace_id = mr.workspace_id,
                patient_id = mr.patient_id
            FROM medical_records mr
            WHERE ma.medical_record_id = mr.id
        ");

        // Make medical_record_id nullable so attachments can exist directly on a patient
        Schema::table('medical_attachments', function (Blueprint $table) {
            $table->foreignId('medical_record_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
            $table->dropForeign(['patient_id']);
            $table->dropColumn('patient_id');
        });
    }
};
