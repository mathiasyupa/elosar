<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal / SRI configuration for the workspace (the legal issuer of vouchers).
 *
 * Ecuadorian electronic invoicing requires every emitter to hold a RUC, an
 * issuing point and a digital signing certificate (.p12 from the BCE).
 * Also stores ACESS/SENESCYT identifiers (informational, no live integration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // ── Fiscal identity ──────────────────────────────────────────
            $table->string('ruc', 13)->nullable()->after('credit_note_counter');
            $table->string('razon_social', 300)->nullable()->after('ruc');
            $table->string('nombre_comercial', 300)->nullable()->after('razon_social');
            $table->text('dir_matriz')->nullable()->after('nombre_comercial');
            $table->text('dir_establecimiento')->nullable()->after('dir_matriz')
                ->comment('Null falls back to dir_matriz');
            $table->char('cod_establecimiento', 3)->default('001')->after('dir_establecimiento');
            $table->char('pto_emision', 3)->default('001')->after('cod_establecimiento');
            $table->string('contribuyente_especial', 13)->nullable()->after('pto_emision');
            $table->boolean('obligado_contabilidad')->default(false)->after('contribuyente_especial');

            // ── Healthcare regulators (informational) ────────────────────
            $table->string('acess_establishment_code', 50)->nullable()->after('obligado_contabilidad')
                ->comment('ACESS UNICODIGO establishment permit');
            $table->string('senescyt_note', 200)->nullable()->after('acess_establishment_code')
                ->comment('Informational only, no live integration');

            // ── SRI electronic billing config ────────────────────────────
            $table->string('sri_environment', 20)->default('test')->after('senescyt_note')
                ->comment('test|production');
            $table->string('sri_certificate_path', 500)->nullable()->after('sri_environment')
                ->comment('.p12 path on the local (private) disk');
            $table->text('sri_certificate_password')->nullable()->after('sri_certificate_path')
                ->comment('Encrypted via Laravel encrypted cast');
            $table->boolean('sri_enabled')->default(false)->after('sri_certificate_password');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn([
                'ruc',
                'razon_social',
                'nombre_comercial',
                'dir_matriz',
                'dir_establecimiento',
                'cod_establecimiento',
                'pto_emision',
                'contribuyente_especial',
                'obligado_contabilidad',
                'acess_establishment_code',
                'senescyt_note',
                'sri_environment',
                'sri_certificate_path',
                'sri_certificate_password',
                'sri_enabled',
            ]);
        });
    }
};
