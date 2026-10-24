<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the 'workspace_members' table — membership table.
     * Tracks which doctors belong to which clinic workspaces,
     * their role within that workspace and whether they are still active.
     *
     * IMPORTANT: This table is NOT used for the doctor's personal workspace.
     * The personal workspace is created automatically when the doctor registers
     * and they always have access to it. This table only manages clinic memberships.
     */
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();

            // Doctor who is a member of the clinic
            $table->foreignId('doctor_id')
                  ->constrained('doctors')
                  ->onDelete('cascade');

            // Clinic workspace they belong to (always type = 'clinic')
            $table->foreignId('workspace_id')
                  ->constrained('workspaces')
                  ->onDelete('cascade');

            // Member role within the clinic:
            // 'doctor' → full access including medical consultations
            // 'staff'  → only vital signs, appointments and basic patient data
            $table->enum('role', ['doctor', 'staff'])->default('doctor');

            // Membership status:
            // 'active'   → the doctor currently works at this clinic
            // 'inactive' → left the clinic, but keeps read access to their own records
            $table->enum('status', ['active', 'inactive'])->default('active');

            // Prevents the same doctor from being duplicated in the same workspace
            $table->unique(['doctor_id', 'workspace_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
