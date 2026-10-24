<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Doctor's LEVEL 1 electronic signature (image).
     *
     * Stores the path to the doctor's signature image, embedded in the prescription
     * PDF. This is the first (visual) level; later in production it can be upgraded
     * to a drawn signature or a certificate-based digital signature.
     *
     * Also stores the medical license number, commonly printed on prescriptions.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('signature_path')->nullable()->after('phone');
            $table->string('medical_license')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn(['signature_path', 'medical_license']);
        });
    }
};
