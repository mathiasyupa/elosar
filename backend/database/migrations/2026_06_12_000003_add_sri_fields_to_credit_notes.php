<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRI authorization tracking for credit notes (codDoc 04).
 *
 * Buyer data is inherited from the voided invoice, so only the SRI
 * authorization columns are added here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->string('sri_sequence_number', 17)->nullable()->after('created_by')
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
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropUnique(['sri_sequence_number']);
            $table->dropUnique(['sri_clave_acceso']);
            $table->dropIndex(['sri_status']);
            $table->dropColumn([
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
