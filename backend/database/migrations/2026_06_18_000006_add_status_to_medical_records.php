<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enables the nurse → doctor triage handoff on the consultation record:
     *  - status: draft (nurse opened) → vitals_done (triage saved) → completed
     *  - triaged_by / triaged_at: who took the vitals and when
     *
     * Default 'completed' keeps every existing one-shot record correct.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('status')->default('completed')->after('appointment_id');
            $table->foreignId('triaged_by')->nullable()->after('status')
                  ->constrained('doctors')->nullOnDelete();
            $table->timestamp('triaged_at')->nullable()->after('triaged_by');
        });

        DB::statement(
            "ALTER TABLE medical_records ADD CONSTRAINT medical_records_status_check
             CHECK (status IN ('draft', 'vitals_done', 'completed'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE medical_records DROP CONSTRAINT IF EXISTS medical_records_status_check');

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('triaged_by');
            $table->dropColumn(['status', 'triaged_at']);
        });
    }
};
