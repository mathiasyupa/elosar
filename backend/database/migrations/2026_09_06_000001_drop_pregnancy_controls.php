<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `pregnancy_controls`, which never had an endpoint or a screen.
 *
 * It was created a week before it was needed, to hold the obstetric measurements
 * of a prenatal visit — fundal height, fetal heart rate, presentation, foetal
 * movements, oedema. Every one of those already lives in
 * `medical_records.structured_exam` under the prenatal template, which since the
 * template registry landed is a validated, versioned allow-list rather than a
 * free-form map. Keeping the table would mean two places to write the same
 * finding and two to read it, which is exactly what ADR-009 argues against for
 * weight and blood pressure.
 *
 * The pregnancy timeline is built from the consultations linked by
 * `medical_records.pregnancy_id` instead. That column stays.
 *
 * Dropping it is safe precisely because nothing ever wrote to it: no controller,
 * no request, no factory. `down()` recreates the shape for the record, but there
 * is no data to restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pregnancy_controls');
    }

    public function down(): void
    {
        Schema::create('pregnancy_controls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->unique()->constrained()->cascadeOnDelete();
            $table->integer('gestational_age_days')->nullable();
            $table->string('dating_source', 20)->nullable();
            $table->string('dating_protocol', 20)->nullable();
            $table->decimal('fundal_height_cm', 4, 1)->nullable();
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->string('fetal_presentation', 20)->nullable();
            $table->boolean('fetal_movements')->nullable();
            $table->string('edema', 20)->nullable();
            $table->string('proteinuria', 10)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
