<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes the loop on a lab / imaging order.
 *
 * Until now an order was fire-and-forget: it was issued, printed, and the
 * system never learned whether the study was performed. Nobody could answer the
 * question the front desk is actually asked all day — "¿ya llegó el resultado
 * de la paciente?" — and a result that did arrive was uploaded as a loose
 * attachment, disconnected from the order that requested it.
 *
 * Deliberately NOT structured analyte values with reference ranges and trends.
 * The competitor does not capture those either (it embeds an external lab
 * system), so this blocks no migration; the expensive version can come later if
 * a clinic's own laboratory justifies it.
 *
 * The result document itself reuses medical_attachments, which is already
 * patient-direct with a private disk, authenticated download and audit. One
 * nullable FK is the whole mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_orders', function (Blueprint $table) {
            // pending | completed | cancelled — see StudyOrder::RESULT_STATUSES.
            $table->string('result_status', 20)->default('pending')->after('priority');
            $table->timestamp('result_received_at')->nullable()->after('to_be_performed_at');
            $table->text('result_notes')->nullable()->after('result_received_at');

            $table->index(['workspace_id', 'result_status']);
        });

        Schema::table('medical_attachments', function (Blueprint $table) {
            // The order this document answers. Nullable: most attachments are
            // not results, and deleting an order must not destroy the file —
            // it stays on the patient, which is where the retention obligation
            // actually sits.
            $table->foreignId('study_order_id')
                ->nullable()
                ->after('medical_record_id')
                ->constrained('study_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('medical_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('study_order_id');
        });

        Schema::table('study_orders', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'result_status']);
            $table->dropColumn(['result_status', 'result_received_at', 'result_notes']);
        });
    }
};
