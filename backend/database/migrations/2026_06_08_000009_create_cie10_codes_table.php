<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cie10_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique()->comment('ICD-10 code, e.g. J06.9');
            $table->string('description', 255)->comment('Diagnostic description');
            $table->string('category', 100)->nullable()->comment('ICD-10 category or chapter');
            $table->timestamps();

            // Index for fast lookup
            $table->index('code');
            $table->index('description');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cie10_codes');
    }
};
