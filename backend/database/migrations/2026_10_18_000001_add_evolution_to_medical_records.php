<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Evolución» becomes its own field, apart from «Enfermedad actual»: doctors
 * write how the patient has been doing since the last visit separately from
 * the present illness (requested by the doctors of a client clinic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $table->text('evolution')->nullable()->after('current_illness');
        });

        // Orpheus' «Evolución» column was imported into current_illness for lack
        // of a field of its own: it moves to where it belongs.
        DB::table('medical_records')
            ->where('import_source', 'orpheus')
            ->whereNotNull('current_illness')
            ->update(['evolution' => DB::raw('current_illness'), 'current_illness' => null]);
    }

    public function down(): void
    {
        DB::table('medical_records')
            ->where('import_source', 'orpheus')
            ->whereNull('current_illness')
            ->whereNotNull('evolution')
            ->update(['current_illness' => DB::raw('evolution')]);

        Schema::table('medical_records', function (Blueprint $table): void {
            $table->dropColumn('evolution');
        });
    }
};
