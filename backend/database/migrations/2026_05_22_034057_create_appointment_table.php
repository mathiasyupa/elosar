<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the 'appointments' table to manage medical appointments in the SaaS.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            // Auto-incrementing primary key
            $table->id();

            // Required relation to 'patients', auto-indexed, cascade delete
            $table->foreignId('patient_id')
                ->constrained('patients')
                ->onDelete('cascade');

            // Required relation to 'doctors', auto-indexed, cascade delete
            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->onDelete('cascade');

            // Scheduled date and time of the appointment (required)
            $table->dateTime('appointment_date');

            // Appointment status (required, defaults to 'scheduled')
            $table->string('status')->default('scheduled');

            // Standard Laravel timestamps (created_at, updated_at) for auditing
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
