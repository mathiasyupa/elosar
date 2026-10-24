<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('concentration', 100)->nullable();
            $table->unsignedTinyInteger('default_dose_quantity')->default(1);
            $table->string('default_dose_unit', 30)->default('tablet');
            $table->string('default_frequency', 50)->default('every_8_hours');
            $table->unsignedSmallInteger('default_duration_days')->nullable();
            $table->string('route', 50)->default('oral');
            $table->boolean('is_controlled')->default(false);
            $table->text('default_instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'is_active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
    }
};
