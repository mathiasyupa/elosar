<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the 'clinic_doctor' pivot table — N:M relation between doctors and clinics.
     *
     * A doctor can work at several clinics.
     * A clinic has several doctors.
     * This table records every possible combination.
     *
     * Difference from workspace_members:
     * - workspace_members → manages access, roles and status (active/inactive)
     * - clinic_doctor     → pure Eloquent pivot for the BelongsToMany relation
     *   between Doctor and Workspace (clinics). Enables clean queries like:
     *   $doctor->clinics  or  $clinic->doctors.
     */
    public function up(): void
    {
        Schema::create('clinic_doctor', function (Blueprint $table) {
            // Linked doctor
            $table->foreignId('doctor_id')
                  ->constrained('doctors')
                  ->onDelete('cascade');

            // Clinic-type workspace the doctor is linked to
            $table->foreignId('workspace_id')
                  ->constrained('workspaces')
                  ->onDelete('cascade');

            // Composite primary key — prevents duplicates
            $table->primary(['doctor_id', 'workspace_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_doctor');
    }
};
