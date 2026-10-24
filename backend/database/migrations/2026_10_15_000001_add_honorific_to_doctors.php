<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a doctor signs: «Dr.» or «Dra.». Null until the doctor chooses it the
 * first time they issue a document (receta, certificado u orden); documents
 * print «Dr.» meanwhile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('honorific', 8)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('honorific');
        });
    }
};
