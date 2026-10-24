<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A walk-in patient is registered with only a name and a cédula. The other
 * demographic/contact columns were NOT NULL, so the walk-in flow had to invent
 * values (a birth date 30 years ago, gender "other", city "Quito", a
 * 0000000000 phone and a placeholder email) that then showed on the chart as
 * if they were real. Make them nullable so a minimal patient genuinely has
 * "sin dato" until someone completes the profile. The full patient form still
 * requires them (StorePatientRequest), so normal registrations are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->date('birth_date')->nullable()->change();
            $table->string('gender')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('phone')->nullable()->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Backfill the rows this change allowed to be null, so restoring NOT
        // NULL can't fail. These are the same placeholders the walk-in used to
        // write inline.
        DB::table('patients')->whereNull('birth_date')->update(['birth_date' => now()->subYears(30)->toDateString()]);
        DB::table('patients')->whereNull('gender')->update(['gender' => 'other']);
        DB::table('patients')->whereNull('city')->update(['city' => 'Quito']);
        DB::table('patients')->whereNull('phone')->update(['phone' => '0000000000']);
        DB::table('patients')->whereNull('email')->update(['email' => DB::raw("'walkin-' || id || '@clinic.local'")]);

        Schema::table('patients', function (Blueprint $table): void {
            $table->date('birth_date')->nullable(false)->change();
            $table->string('gender')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
            $table->string('phone')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
        });
    }
};
