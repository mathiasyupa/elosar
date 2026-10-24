<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates the 'specialties' table as a catalog of medical specialties.
     */
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            // Auto-incrementing primary key
            $table->id();

            // Specialty name (Cardiology, Pediatrics, etc.)
            $table->string('name');

            // Optional clinical description or notes about the medical field
            $table->text('description')->nullable();

            // Standard Laravel timestamps (created_at, updated_at)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('specialties');
    }
};
