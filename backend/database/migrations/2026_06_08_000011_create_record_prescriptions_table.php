<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_prescriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();

            $table->string('medication_name', 255)->comment('Medication name');
            $table->string('concentration', 100)->nullable()->comment('Concentration, e.g. 500mg, 10mg/5ml');
            $table->string('dosage', 100)->comment('Dosage, e.g. 1 tablet, 5ml');

            $table->string('route', 50)->nullable()
                ->comment('Route of administration: oral|sublingual|iv|im|sc|topical|inhaled|rectal|other');

            $table->string('frequency', 100)->comment('Frequency, e.g. every 8 hours, once a day');
            $table->unsignedSmallInteger('duration_days')->nullable()->comment('Duration in days');

            $table->text('instructions')->nullable()->comment('Additional instructions for the patient');
            $table->boolean('is_controlled')->default(false)->comment('Controlled medication (psychotropic, etc.)');

            $table->timestamps();

            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_prescriptions');
    }
};
