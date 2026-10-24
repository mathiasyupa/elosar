<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the 'workspaces' table — the central entity of the multi-tenant system.
     * Each workspace is a working space: either a doctor's personal practice
     * (type = personal) or a clinic/organization (type = clinic).
     * Every record in the system (patients, appointments, medical records) belongs to a workspace.
     */
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            // Auto-incrementing PK
            $table->id();

            // Workspace name (e.g. "Consultorio Dr. García" or "Clínica Pichincha")
            $table->string('name');

            // Workspace type: 'personal' (doctor's own practice) or 'clinic' (organization)
            $table->enum('type', ['personal', 'clinic'])->default('personal');

            // Fixed invite code so doctors can join a clinic.
            // Only applies to 'clinic' workspaces. Unique across the table.
            $table->string('invite_code', 12)->nullable()->unique();

            // Workspace subscription status
            $table->enum('subscription_status', ['trial', 'active', 'expired', 'cancelled'])
                  ->default('trial');

            // Doctor who owns/administers this workspace.
            // Nullable to allow creating the workspace before assigning an owner,
            // and for future clinic workspaces with multiple administrators.
            $table->foreignId('owner_doctor_id')
                  ->nullable()
                  ->constrained('doctors')
                  ->onDelete('set null');

            // Standard Laravel timestamps
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
