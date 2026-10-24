<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-invoice attending-doctor commission as a FIXED AMOUNT, alongside the
     * existing percentage. Common in Ecuadorian clinics: "we charged $25, the
     * doctor gets $19, the clinic keeps $6".
     *
     * Mutually exclusive with attending_doctor_commission_pct (enforced by
     * StoreInvoiceRequest). In the settlement the doctor is paid FIRST: they
     * receive min(amount, collected), so a partially-paid invoice pays the
     * doctor up to the fixed amount and the clinic keeps the remainder.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->decimal('attending_doctor_commission_amount', 10, 2)
                ->nullable()
                ->after('attending_doctor_commission_pct');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('attending_doctor_commission_amount');
        });
    }
};
