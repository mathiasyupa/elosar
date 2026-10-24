<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_vitals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('medical_record_id')
                ->constrained('medical_records')
                ->cascadeOnDelete();

            $table->decimal('weight_kg', 5, 2)->nullable()->comment('Weight in kilograms');
            $table->decimal('height_cm', 5, 2)->nullable()->comment('Height in centimeters');
            $table->decimal('bmi', 5, 2)->nullable()->comment('Body mass index');

            $table->unsignedSmallInteger('blood_pressure_sys')->nullable()->comment('Systolic blood pressure mmHg');
            $table->unsignedSmallInteger('blood_pressure_dia')->nullable()->comment('Diastolic blood pressure mmHg');
            $table->unsignedSmallInteger('heart_rate')->nullable()->comment('Heart rate bpm');
            $table->decimal('temperature', 4, 1)->nullable()->comment('Body temperature °C');
            $table->decimal('oxygen_saturation', 4, 1)->nullable()->comment('Oxygen saturation SpO2 %');
            $table->unsignedSmallInteger('respiratory_rate')->nullable()->comment('Respiratory rate rpm');

            $table->dateTime('recorded_at')->comment('Timestamp when vital signs were taken');

            $table->timestamps();

            $table->index('medical_record_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_vitals');
    }
};
