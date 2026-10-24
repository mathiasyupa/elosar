<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('discount_percentage', 5, 2)->default(0)->after('iva_rate')
                ->comment('Discount percentage applied to the item (0-100)');
            $table->decimal('discount_amount', 10, 2)->default(0)->after('discount_percentage')
                ->comment('Calculated discount amount in USD');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->default(0)->after('iva_amount')
                ->comment('Global discount applied to the invoice in USD');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['discount_percentage', 'discount_amount']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
