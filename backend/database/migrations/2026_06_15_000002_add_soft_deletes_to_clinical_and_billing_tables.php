<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for clinical and billing records.
 *
 * These rows carry legal/clinical weight (medical history, electronic invoices
 * with a 7-year retention obligation). A physical delete is irreversible and,
 * for authorized SRI vouchers, unlawful. Soft deletes keep the row recoverable
 * and retained; hard-deleting an authorized voucher is additionally blocked at
 * the model level (see App\Models\Concerns\GuardsSriRetention).
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'patients',
        'medical_records',
        'record_diagnoses',
        'record_prescriptions',
        'invoices',
        'credit_notes',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
