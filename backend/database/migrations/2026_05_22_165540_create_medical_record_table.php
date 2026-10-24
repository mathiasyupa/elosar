<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the 'medical_records' table with clinical fields and relations.
     */
    public function up(): void
    {
        Schema::create('medical_records', function (Blueprint $table) {
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

            // Reason for consultation (long text, required)
            $table->text('reason_for_consultation');

            // Current illness or symptoms described by the patient (optional)
            $table->text('current_illness')->nullable();

            // Presumptive or definitive clinical diagnosis (required, ideally ICD-10 coded)
            $table->text('diagnosis');

            // Therapeutic plan or prescribed treatment (required)
            $table->text('treatment');

            // Standard Laravel timestamps (created_at, updated_at) for consultation history
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_records');
    }
};
