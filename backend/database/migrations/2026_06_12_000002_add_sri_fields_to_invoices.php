<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer (comprador) fiscal data + SRI authorization tracking for invoices.
 *
 * The buyer may differ from the linked patient (B2B billing), so the fiscal
 * identification is denormalized onto the invoice at issue time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // ── Buyer (comprador) ────────────────────────────────────────
            $table->char('buyer_id_type', 2)->default('05')->after('created_by')
                ->comment('04=RUC 05=cedula 06=passport 07=consumidor_final');
            $table->string('buyer_id', 20)->nullable()->after('buyer_id_type');
            $table->string('buyer_name', 300)->nullable()->after('buyer_id');
            $table->text('buyer_address')->nullable()->after('buyer_name');
            $table->string('buyer_email', 255)->nullable()->after('buyer_address');

            // ── SRI authorization ────────────────────────────────────────
            $table->string('sri_sequence_number', 17)->nullable()->after('buyer_email')
                ->comment('estab-ptoEmi-secuencial e.g. 001-001-000000001');
            $table->char('sri_clave_acceso', 49)->nullable()->after('sri_sequence_number');
            $table->string('sri_authorization_number', 49)->nullable()->after('sri_clave_acceso');
            $table->timestamp('sri_authorization_date')->nullable()->after('sri_authorization_number');
            $table->string('sri_status', 20)->nullable()->after('sri_authorization_date')
                ->comment('pending|authorized|rejected');
            $table->text('sri_xml')->nullable()->after('sri_status');
            $table->string('sri_environment', 10)->nullable()->after('sri_xml');
            $table->smallInteger('sri_submission_attempts')->default(0)->after('sri_environment');
            $table->text('sri_last_error')->nullable()->after('sri_submission_attempts');

            $table->unique('sri_sequence_number');
            $table->unique('sri_clave_acceso');
            $table->index('sri_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['sri_sequence_number']);
            $table->dropUnique(['sri_clave_acceso']);
            $table->dropIndex(['sri_status']);
            $table->dropColumn([
                'buyer_id_type',
                'buyer_id',
                'buyer_name',
                'buyer_address',
                'buyer_email',
                'sri_sequence_number',
                'sri_clave_acceso',
                'sri_authorization_number',
                'sri_authorization_date',
                'sri_status',
                'sri_xml',
                'sri_environment',
                'sri_submission_attempts',
                'sri_last_error',
            ]);
        });
    }
};
