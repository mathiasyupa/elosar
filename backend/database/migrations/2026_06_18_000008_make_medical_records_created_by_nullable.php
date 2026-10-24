<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A triage draft is opened by a nurse (staff) who has no Doctor record and
     * captures vitals only — the clinical narrative comes later when the doctor
     * completes the consultation. So the columns a draft cannot yet have are made
     * nullable: created_by (authoring doctor), reason_for_consultation, diagnosis
     * and treatment. They are populated on completion.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
            $table->text('reason_for_consultation')->nullable()->change();
            $table->text('diagnosis')->nullable()->change();
            $table->text('treatment')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
            $table->text('reason_for_consultation')->nullable(false)->change();
            $table->text('diagnosis')->nullable(false)->change();
            $table->text('treatment')->nullable(false)->change();
        });
    }
};
