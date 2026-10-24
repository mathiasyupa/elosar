<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the 'patients' table with the full clinical profile.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            // Auto-incrementing primary key
            $table->id();

            // Patient full name (required)
            $table->string('name');

            // Ecuadorian national ID (cédula) — required, unique, max 10 characters
            $table->string('id_number', 10)->unique();

            // Patient date of birth (required)
            $table->date('birth_date');

            // Patient gender (required)
            $table->string('gender');

            // City of residence (required)
            $table->string('city');

            // Contact email (required)
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
        Schema::dropIfExists('patients');
    }
};
