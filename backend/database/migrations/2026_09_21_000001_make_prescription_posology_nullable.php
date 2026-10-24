<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prescribing a topical cream asked for twelve fields. From 2026-09-21 the
 * prescription form shows three (name, dispensing quantity, instructions) plus
 * the controlled-substance switch, so structured posology is no longer
 * captured for new prescriptions and these two columns can be absent.
 *
 * Existing rows keep their values: this only drops the NOT NULL constraint,
 * it does not rewrite data. `quantity` stays required at the request layer —
 * it is the MSP "cantidad a dispensar" and already had a defect once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_prescriptions', function (Blueprint $table): void {
            $table->string('dosage', 100)->nullable()->comment('Dosage, e.g. 1 tablet, 5ml')->change();
            $table->string('frequency', 100)->nullable()->comment('Frequency, e.g. every 8 hours, once a day')->change();
        });
    }

    public function down(): void
    {
        // Backfill the rows this change allowed to be null, so restoring NOT
        // NULL cannot fail. An empty string is honest here: it says "not
        // recorded" without inventing a posology on a clinical record.
        DB::table('record_prescriptions')->whereNull('dosage')->update(['dosage' => '']);
        DB::table('record_prescriptions')->whereNull('frequency')->update(['frequency' => '']);

        Schema::table('record_prescriptions', function (Blueprint $table): void {
            $table->string('dosage', 100)->nullable(false)->comment('Dosage, e.g. 1 tablet, 5ml')->change();
            $table->string('frequency', 100)->nullable(false)->comment('Frequency, e.g. every 8 hours, once a day')->change();
        });
    }
};
