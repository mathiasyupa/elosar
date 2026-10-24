<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vaccination scheme (Ecuador PAI — Programa Ampliado de Inmunizaciones).
 *
 * `vaccines` is the global catalog (the rows of the vaccination card, with the
 * number of doses/boosters the scheme defines). `patient_immunizations` records
 * each administered dose as an event (one row per dose/booster given), which the
 * UI aggregates into the dose-by-dose grid. Administration is attributed to a
 * USER (covers nurses, who have no Doctor record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vaccines', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->nullable();
            $table->string('name', 255);
            $table->string('category', 60)->nullable(); // PAI / No PAI / Tratamiento
            $table->unsignedTinyInteger('max_doses')->default(1);
            $table->unsignedTinyInteger('max_boosters')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index('name');
        });

        Schema::create('patient_immunizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vaccine_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vaccine_name', 255);          // snapshot
            $table->string('dose_label', 30);             // dose_1..3 | booster_1..3 | single
            $table->date('administered_at');
            $table->string('lot', 80)->nullable();        // lote
            $table->text('notes')->nullable();            // observaciones
            $table->date('reminder_at')->nullable();      // recordatorio próxima dosis
            $table->foreignId('administered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'patient_id']);
            $table->index(['patient_id', 'vaccine_id']);
        });

        // Row-level write audit, consistent with the rest of the clinical layer.
        DB::unprepared(
            'CREATE TRIGGER patient_immunizations_audit '
            . 'AFTER INSERT OR UPDATE OR DELETE ON patient_immunizations '
            . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS patient_immunizations_audit ON patient_immunizations;');

        Schema::dropIfExists('patient_immunizations');
        Schema::dropIfExists('vaccines');
    }
};
