<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MSP demographic fields required by the RDACAA ambulatory production export
 * (Registro Diario Automatizado de Consultas y Atenciones Ambulatorias).
 *
 * These describe WHO was attended in the terms the MSP catalogs expect
 * (document type, nationality, self-identified ethnicity, priority group,
 * disability). All nullable so existing patients stay valid and can be
 * back-filled through the normal patient edit flow; RDACAA rows with missing
 * values fall back to the MSP "no reportado" code at export time.
 *
 * The establishment "unicódigo" the RDACAA needs already lives on the workspace
 * as `acess_establishment_code` (ACESS UNICODIGO) — no new workspace column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('document_type', 20)->nullable()->after('id_number')
                ->comment('MSP: cedula|passport|other — type of the id_number');
            $table->string('nationality', 60)->nullable()->after('city');
            $table->string('ethnicity', 40)->nullable()->after('nationality')
                ->comment('MSP autoidentificación: mestizo|indigena|afro|montubio|blanco|otro');
            $table->string('priority_group', 60)->nullable()->after('ethnicity')
                ->comment('MSP grupo prioritario');
            $table->string('disability_type', 40)->nullable()->after('priority_group');
            $table->unsignedTinyInteger('disability_pct')->nullable()->after('disability_type');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn([
                'document_type',
                'nationality',
                'ethnicity',
                'priority_group',
                'disability_type',
                'disability_pct',
            ]);
        });
    }
};
