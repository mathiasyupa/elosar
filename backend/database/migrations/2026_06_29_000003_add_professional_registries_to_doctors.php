<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Professional registries that must appear on the doctor's clinical documents
 * (Ecuador): the ACESS professional registration code (calificación del
 * profesional de la salud) and the SENESCYT title registration number. These
 * complement the MSP `medical_license` already stored on the doctor and the
 * establishment-level `acess_establishment_code` on the workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('acess_code', 60)->nullable()->after('medical_license');
            $table->string('senescyt_registration', 60)->nullable()->after('acess_code');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn(['acess_code', 'senescyt_registration']);
        });
    }
};
