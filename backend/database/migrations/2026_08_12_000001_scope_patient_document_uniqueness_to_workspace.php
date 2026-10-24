<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A previous nullable tuple could have allowed rows which collide once
        // nulls and country casing are normalized. Check before mutating so a
        // deploy remains safely retryable if data needs reconciliation.
        $collisions = DB::table('patients')
            ->selectRaw('1')
            ->groupByRaw("workspace_id, COALESCE(document_type, 'cedula'), COALESCE(UPPER(document_country), 'EC'), id_number")
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($collisions > 0) {
            throw new RuntimeException(
                "Cannot scope patient document uniqueness: {$collisions} duplicate document tuple(s) exist within a workspace. Reconcile them before retrying the migration.",
            );
        }

        // Old records predate the document tuple. Normalize them before the
        // constraint closes the NULL loophole in PostgreSQL unique indexes.
        DB::table('patients')->whereNull('document_type')->update(['document_type' => 'cedula']);
        DB::table('patients')->whereNull('document_country')->update(['document_country' => 'EC']);
        DB::table('patients')->update(['document_country' => DB::raw('UPPER(document_country)')]);

        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique('patients_document_type_document_country_id_number_unique');
            $table->string('document_type', 20)->default('cedula')->change();
            $table->string('document_country', 2)->default('EC')->change();
            $table->unique(
                ['workspace_id', 'document_type', 'document_country', 'id_number'],
                'patients_workspace_document_unique',
            );
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This migration cannot be safely rolled back after documents have been reused in different workspaces.',
        );
    }
};
