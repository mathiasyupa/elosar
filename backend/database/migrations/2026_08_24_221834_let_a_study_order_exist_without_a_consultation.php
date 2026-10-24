<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order used to require a consultation to hang from, which forced a doctor
 * who was only asked for a repeat lab slip to invent an empty visit — and that
 * invented visit stayed in the patient's history for good.
 *
 * The order was already almost independent: it carries its own `clinical_info`,
 * and the PDF reads that first, falling back to the consultation's diagnoses
 * only when it is missing. What tied them together was this column.
 *
 * `patient_id` and `workspace_id` stay NOT NULL. They are what the isolation
 * rests on: an order always belongs to a patient, and a patient always belongs
 * to a workspace. Only the consultation becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_orders', function (Blueprint $table) {
            $table->foreignId('medical_record_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Orders issued without a consultation cannot be given one on the way
        // back, so they would break the NOT NULL. Dropping them would destroy
        // clinical documents; the reversal deliberately refuses instead.
        if (\DB::table('study_orders')->whereNull('medical_record_id')->exists()) {
            throw new RuntimeException(
                'Hay órdenes emitidas sin consulta. Revísalas antes de revertir: '
                . 'volver atrás exigiría borrarlas o inventarles una consulta.'
            );
        }

        Schema::table('study_orders', function (Blueprint $table) {
            $table->foreignId('medical_record_id')->nullable(false)->change();
        });
    }
};
