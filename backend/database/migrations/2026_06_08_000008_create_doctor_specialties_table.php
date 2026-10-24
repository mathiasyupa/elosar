<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_specialties', function (Blueprint $table) {
            $table->foreignId('doctor_id')
                ->constrained('doctors')
                ->cascadeOnDelete();

            $table->foreignId('specialty_id')
                ->constrained('specialties')
                ->cascadeOnDelete();

            $table->boolean('is_primary')->default(false)->comment('Primary specialty of the doctor');

            $table->timestamps();

            $table->primary(['doctor_id', 'specialty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_specialties');
    }
};
