<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the "overdue" invoice concept (no due date, no overdue status) and adds
 * a per-invoice attending-doctor commission percentage the cashier sets at billing
 * time. Health services in Ecuador are IVA-exempt, so the commission basis is the
 * subtotal — the settlement (DoctorSettlementService) prefers this per-invoice %
 * over the doctor's global commission_pct / per-service rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Re-classify any invoice still marked 'overdue' back to a live status,
        // now that the status no longer exists.
        DB::table('invoices')
            ->where('status', 'overdue')
            ->update([
                'status' => DB::raw(
                    "CASE
                        WHEN amount_paid >= total THEN 'paid'
                        WHEN amount_paid > 0 THEN 'partial'
                        ELSE 'issued'
                    END"
                ),
            ]);

        Schema::table('invoices', function (Blueprint $table): void {
            // Doctor revenue-share for THIS attention (0–100), captured at billing
            // time. Null = fall back to the doctor's global commission_pct / rules.
            $table->decimal('attending_doctor_commission_pct', 5, 2)->nullable()->after('attending_doctor_id');

            if (Schema::hasColumn('invoices', 'due_at')) {
                $table->dropColumn('due_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('due_at')->nullable()->after('issued_at');
            $table->dropColumn('attending_doctor_commission_pct');
        });
    }
};
