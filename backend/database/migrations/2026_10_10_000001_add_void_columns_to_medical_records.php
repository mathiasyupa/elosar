<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit saved by mistake can be voided by its author. Clinical history is
 * never hard-deleted: the record is soft-deleted, and these columns keep who
 * voided it and why next to it (the audit trigger keeps the full before/after).
 *
 * `opened_own_appointment` marks a visit whose appointment was created by the
 * visit itself (a walk-in finalized without one). Voiding cancels that
 * appointment; an appointment that came from the agenda goes back to pending
 * instead. Existing rows default to false — treated as agenda appointments,
 * which only ever reopens one, never cancels it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->boolean('opened_own_appointment')->default(false);
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['opened_own_appointment', 'voided_at', 'void_reason']);
        });
    }
};
