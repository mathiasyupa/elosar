<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `status` and `created_by` on `medical_records` had no index of their own —
 * PostgreSQL, unlike MySQL, does not index a foreign-key column just because
 * it is constrained. Every report query that filters completed records by
 * date (morbidity, attentions, demographics, medications, pending follow-ups)
 * fell back to scanning a tenant's whole history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->index(['workspace_id', 'status', 'created_at'], 'medical_records_workspace_id_status_created_at_index');
            $table->index(['workspace_id', 'created_by'], 'medical_records_workspace_id_created_by_index');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropIndex('medical_records_workspace_id_status_created_at_index');
            $table->dropIndex('medical_records_workspace_id_created_by_index');
        });
    }
};
