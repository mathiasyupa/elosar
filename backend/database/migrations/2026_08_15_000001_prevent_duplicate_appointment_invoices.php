<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // PostgreSQL allows multiple NULL values in a unique index, so direct
            // invoices remain valid while an appointment can be invoiced only once.
            $table->unique('appointment_id', 'invoices_appointment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique('invoices_appointment_id_unique');
        });
    }
};
