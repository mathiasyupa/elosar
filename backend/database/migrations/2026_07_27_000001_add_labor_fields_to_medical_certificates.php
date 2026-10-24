<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "certificado médico" an Ecuadorian clinic actually hands out: the one a
     * patient takes to their employer or the IESS. It is not free text — it is a
     * fixed list of parameters (contingencia, empresa, actividad, síntomas,
     * diagnóstico + CIE-10, tratamiento, reposo desde/hasta), so each one gets
     * its own column instead of being buried in `body`.
     *
     * All nullable: the existing free-text certificates (constancia, aptitud)
     * keep working untouched.
     */
    public function up(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table): void {
            $table->string('city', 120)->nullable()->after('type');
            // The clinical history number the clinic prints; usually the cédula.
            $table->string('hc_number', 40)->nullable()->after('city');
            $table->string('address', 255)->nullable()->after('hc_number');
            $table->string('phone', 40)->nullable()->after('address');
            $table->string('contingency_type', 60)->nullable()->after('phone');
            $table->string('company', 160)->nullable()->after('contingency_type');
            $table->string('activity', 160)->nullable()->after('company');
            $table->date('attended_at')->nullable()->after('activity');
            $table->text('symptoms')->nullable()->after('attended_at');
            $table->text('diagnosis')->nullable()->after('symptoms');
            $table->string('cie10_codes', 120)->nullable()->after('diagnosis');
            $table->string('treatment', 255)->nullable()->after('cie10_codes');
            $table->boolean('absolute_rest')->nullable()->after('treatment');
        });
    }

    public function down(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table): void {
            $table->dropColumn([
                'city', 'hc_number', 'address', 'phone', 'contingency_type', 'company',
                'activity', 'attended_at', 'symptoms', 'diagnosis', 'cie10_codes',
                'treatment', 'absolute_rest',
            ]);
        });
    }
};
