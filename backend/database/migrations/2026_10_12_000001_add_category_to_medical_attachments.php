<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an uploaded file is (a lab result, an X-ray, an outside prescription…),
 * chosen by whoever uploads it. Until now the document library guessed it from
 * the file name and filed every unknown PDF as a prescription. Nullable: older
 * uploads keep being inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->string('category', 20)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
