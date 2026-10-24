<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fields required for an MSP-compliant prescription (Ecuador):
 * - per medication: quantity to dispense + generic name (DCI);
 * - per record: recommendations, alarm signs, and the official number of the
 *   special controlled-substance prescription pad (entered by the prescriber —
 *   the numbering is issued by the authority, not generated here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_prescriptions', function (Blueprint $table) {
            $table->integer('quantity')->nullable()->after('duration_days');
            $table->string('generic_name', 255)->nullable()->after('medication_name');
        });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->text('recommendations')->nullable()->after('treatment');
            $table->text('alarm_signs')->nullable()->after('recommendations');
            $table->string('controlled_prescription_no', 60)->nullable()->after('alarm_signs');
        });
    }

    public function down(): void
    {
        Schema::table('record_prescriptions', function (Blueprint $table) {
            $table->dropColumn(['quantity', 'generic_name']);
        });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['recommendations', 'alarm_signs', 'controlled_prescription_no']);
        });
    }
};
