<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the credit-note SRI sequence-number uniqueness to the workspace.
 *
 * Mirror of 2026_06_15_000001 (invoices). The credit-note secuencial
 * (estab-ptoEmi-secuencial, e.g. 001-001-000000001) is derived from the
 * PER-WORKSPACE credit_note_counter. Two different workspaces sharing the
 * default establishment / emission point both produce the SAME string for
 * their first credit note, so the original global UNIQUE(sri_sequence_number)
 * would fail the INSERT of the second workspace.
 *
 * The clave de acceso stays globally unique on purpose: it embeds the issuer
 * RUC, so it cannot collide between workspaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropUnique(['sri_sequence_number']);
            $table->unique(['workspace_id', 'sri_sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'sri_sequence_number']);
            $table->unique('sri_sequence_number');
        });
    }
};
