<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the SRI sequence-number uniqueness to the workspace.
 *
 * The SRI secuencial (estab-ptoEmi-secuencial, e.g. 001-001-000000001) is
 * derived from the PER-WORKSPACE invoice counter. Two different workspaces
 * sharing the default establishment / emission point both produce the SAME
 * string for their first invoice, so the original global
 * UNIQUE(sri_sequence_number) would fail the INSERT of the second workspace.
 *
 * The clave de acceso stays globally unique on purpose: it embeds the issuer
 * RUC, so it cannot collide between workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['sri_sequence_number']);
            $table->unique(['workspace_id', 'sri_sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'sri_sequence_number']);
            $table->unique('sri_sequence_number');
        });
    }
};
