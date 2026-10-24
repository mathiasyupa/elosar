<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite indexes led by workspace_id for the most-filtered columns of the
 * scoped tables.
 *
 * Every WorkspaceScope query already filters by workspace_id, so a leading
 * workspace_id column lets PostgreSQL serve the common "this tenant, ordered by
 * date / by patient" access patterns from a single index instead of the
 * single-column FK index plus a sort/filter on top.
 *
 * Only the implicit single-column FK indexes existed before; these composites
 * do not duplicate any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Agenda view: a tenant's appointments ordered by date.
            $table->index(['workspace_id', 'appointment_date'], 'appointments_workspace_id_appointment_date_index');
            // A tenant's appointments for a given patient.
            $table->index(['workspace_id', 'patient_id'], 'appointments_workspace_id_patient_id_index');
        });

        Schema::table('medical_records', function (Blueprint $table) {
            // A tenant's clinical history for a given patient.
            $table->index(['workspace_id', 'patient_id'], 'medical_records_workspace_id_patient_id_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            // Lookup by national id within a tenant (search / dedupe).
            $table->index(['workspace_id', 'id_number'], 'patients_workspace_id_id_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_workspace_id_appointment_date_index');
            $table->dropIndex('appointments_workspace_id_patient_id_index');
        });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex('medical_records_workspace_id_patient_id_index');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex('patients_workspace_id_id_number_index');
        });
    }
};
