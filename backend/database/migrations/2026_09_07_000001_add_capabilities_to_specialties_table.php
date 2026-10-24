<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A clinical module belongs to an approved specialty, never to the string a
     * doctor happened to enter during registration. New/self-service rows get an
     * empty, closed-by-default set; this migration grants the first approved
     * capability only to the canonical catalog row.
     */
    public function up(): void
    {
        Schema::table('specialties', function (Blueprint $table): void {
            $table->jsonb('capabilities')->default('[]');
        });

        DB::table('specialties')
            ->where('name', 'Ginecología y Obstetricia')
            ->update([
                'capabilities' => json_encode(['gyneco_obstetrics'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('specialties', function (Blueprint $table): void {
            $table->dropColumn('capabilities');
        });
    }
};
