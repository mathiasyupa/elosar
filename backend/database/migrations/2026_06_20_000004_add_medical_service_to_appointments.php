<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an appointment to the service being provided (e.g. "Consulta
 * Gineco-Obstétrica"), from the workspace service catalog. Pre-fills billing
 * and shows on the agenda list. Nullable so existing/legacy appointments are
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('medical_service_id')->nullable()->after('type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('medical_service_id');
        });
    }
};
