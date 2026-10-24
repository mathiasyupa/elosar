<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Typed, structured physical examination for the medical record.
 *
 * The competitor's "Exploración" screen swaps its sections by attention type
 * (general / gynecological / pediatric psychomotor development / prenatal /
 * preventive). Rather than rigid columns per template, the structured fields are
 * stored as JSON (`structured_exam`) keyed by the section template selected via
 * `consultation_type`. The free-text `physical_examination` stays as the general
 * narrative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('consultation_type', 30)->default('general')->after('reason_for_consultation');
            $table->jsonb('structured_exam')->nullable()->after('physical_examination');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn(['consultation_type', 'structured_exam']);
        });
    }
};
