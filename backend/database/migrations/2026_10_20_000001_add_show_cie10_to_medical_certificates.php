<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the CIE-10 codes print on the certificate. Some patients do not want
 * their diagnosis code on a note for their employer; the doctor chooses. True
 * by default, so every certificate already issued prints as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table): void {
            $table->boolean('show_cie10')->default(true)->after('cie10_codes');
        });
    }

    public function down(): void
    {
        Schema::table('medical_certificates', function (Blueprint $table): void {
            $table->dropColumn('show_cie10');
        });
    }
};
