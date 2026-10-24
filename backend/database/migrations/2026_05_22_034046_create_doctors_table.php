<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the 'doctors' table, related to specialties.
     */
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            // Auto-incrementing primary key
            $table->id();

            // Doctor full name (required)
            $table->string('name');

            // Required indexed relation to specialties, with cascade delete
            $table->foreignId('specialty_id')
                ->constrained('specialties')
                ->onDelete('cascade');

            // Ecuadorian national ID (cédula) — required, unique, max 10 characters
            $table->string('id_number', 10)->unique();

            // City of practice/residence (required)
            $table->string('city');

            // Institutional contact email (required)
            $table->string('email');

            // Contact phone or mobile number (required)
            $table->string('phone');

            // Standard Laravel timestamps (created_at, updated_at) for auditing
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
