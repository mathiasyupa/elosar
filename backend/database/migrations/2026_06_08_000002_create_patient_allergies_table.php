<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('allergen_type', 30)->default('other'); // drug|food|environmental|other
            $table->string('allergen_name', 255);
            $table->string('severity', 20)->default('mild'); // mild|moderate|severe
            $table->text('notes')->nullable();
            $table->date('diagnosed_at')->nullable();
            $table->timestamps();

            $table->index('patient_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
    }
};
