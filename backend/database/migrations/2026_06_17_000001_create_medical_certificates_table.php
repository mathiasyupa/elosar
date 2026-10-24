<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medical certificates (constancia, reposo, aptitud) — workspace-scoped,
     * patient-facing PDFs that reuse the prescription letterhead.
     */
    public function up(): void
    {
        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('medical_record_id')->nullable()->constrained('medical_records')->nullOnDelete();
            $table->string('type', 32);
            $table->string('title');
            $table->text('body');
            $table->unsignedSmallInteger('rest_days')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('issued_at');
            $table->timestamps();

            $table->index(['workspace_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');
    }
};
