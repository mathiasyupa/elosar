<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('medical_attachments', function (Blueprint $table) {
            $table->id();
            // Links the file to a specific appointment
            $table->foreignId('appointment_id')
                ->constrained('appointments')
                ->onDelete('cascade');

            $table->string('title'); // E.g. "Chest X-ray", "Blood test"
            $table->string('file_path'); // Storage path where the physical file is kept
            $table->text('notes')->nullable(); // Quick notes about the finding
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_attachments');
    }
};
