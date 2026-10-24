<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-service commission overrides for a doctor in a clinic.
 *
 * Real clinics rarely pay a single flat percentage: a doctor may earn 60% of a
 * consultation but a fixed $200 per surgery. Each row overrides, for one service,
 * how that doctor's share is computed — a percentage (0-100) or a fixed amount
 * (money the doctor receives per unit). Services without a rule fall back to the
 * doctor's global commission_pct on the membership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('medical_service_id')->constrained('medical_services')->cascadeOnDelete();
            $table->string('rule_type', 12)->comment('percentage | fixed');
            $table->decimal('value', 12, 2)->comment('percentage 0-100, or fixed money the doctor receives per unit');
            $table->timestamps();

            $table->unique(['workspace_id', 'doctor_id', 'medical_service_id']);
            $table->index(['workspace_id', 'doctor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_commission_rules');
    }
};
