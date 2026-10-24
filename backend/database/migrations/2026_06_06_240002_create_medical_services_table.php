<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->decimal('iva_rate', 5, 2)->default(0.00)->comment('0.00 = exempt, 15.00 = taxed');
            $table->boolean('is_active')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_services');
    }
};
