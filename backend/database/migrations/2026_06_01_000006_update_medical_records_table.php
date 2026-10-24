<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modifies the 'medical_records' table with two changes:
     *
     * 1. Adds 'workspace_id' → identifies the context where the consultation happened.
     *
     * 2. Renames 'doctor_id' → 'created_by'.
     *    Rationale: in the previous model doctor_id implied patient ownership.
     *    In the new model the workspace is the owner. 'created_by' only states
     *    "this doctor wrote this clinical note", which is clinically and legally
     *    important (continuity of care, traceability) but not ownership.
     *    If the doctor leaves the clinic, they keep read access to records they authored.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            // Add workspace_id
            $table->foreignId('workspace_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('workspaces')
                  ->onDelete('set null');

            // Rename doctor_id → created_by
            $table->renameColumn('doctor_id', 'created_by');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->renameColumn('created_by', 'doctor_id');

            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
