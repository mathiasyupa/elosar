<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing attribution for the clinic cobro flow:
     *  - invoices.attending_doctor_id: the physician who saw the patient (source
     *    of truth for "ingresos por médico"). Survives walk-ins (no appointment).
     *  - invoices.issued_by_user_id / payments.registered_by_user_id: WHO keyed
     *    the invoice / took the money (a user — covers cashier staff with no
     *    Doctor record). The legacy doctor columns (created_by / registered_by)
     *    stay for backward compatibility.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('attending_doctor_id')->nullable()->after('appointment_id')
                  ->constrained('doctors')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->after('created_by')
                  ->constrained('users')->nullOnDelete();
            $table->index('attending_doctor_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('registered_by_user_id')->nullable()->after('registered_by')
                  ->constrained('users')->nullOnDelete();
        });

        // Backfill attending physician from the linked appointment.
        DB::statement(
            'UPDATE invoices i SET attending_doctor_id = a.doctor_id
             FROM appointments a
             WHERE i.appointment_id = a.id AND i.attending_doctor_id IS NULL'
        );

        // Backfill the "who" user columns from the legacy doctor -> user link.
        DB::statement(
            'UPDATE invoices i SET issued_by_user_id = d.user_id
             FROM doctors d
             WHERE i.created_by = d.id AND d.user_id IS NOT NULL AND i.issued_by_user_id IS NULL'
        );
        DB::statement(
            'UPDATE payments p SET registered_by_user_id = d.user_id
             FROM doctors d
             WHERE p.registered_by = d.id AND d.user_id IS NOT NULL AND p.registered_by_user_id IS NULL'
        );
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by_user_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('issued_by_user_id');
            $table->dropConstrainedForeignId('attending_doctor_id');
        });
    }
};
