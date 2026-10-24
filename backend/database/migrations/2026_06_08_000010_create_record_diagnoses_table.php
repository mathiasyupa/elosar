<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_diagnoses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();

            // No FK to cie10_codes — allows free-form codes not present in the catalog
            $table->string('cie10_code', 10)->comment('ICD-10 code, e.g. J06.9');
            $table->string('cie10_description', 255)->comment('Diagnosis description');

            $table->string('type', 20)->default('primary')
                ->comment('Type: primary|secondary|presumptive');

            $table->timestamps();

            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_diagnoses');
    }
};
