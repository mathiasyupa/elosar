<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds 'workspace_id' to the 'doctors' table.
     * Each doctor has a personal workspace created automatically on registration.
     * This field points to that workspace.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            // Nullable to avoid breaking existing rows during the migration.
            // Populated via seeder/command after personal workspaces are created.
            $table->foreignId('workspace_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('workspaces')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropForeign(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
