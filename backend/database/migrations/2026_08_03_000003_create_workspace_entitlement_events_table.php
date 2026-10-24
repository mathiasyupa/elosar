<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_entitlement_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('workspace_entitlement_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 64);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_entitlement_id', 'workspace_id'])
                ->references(['id', 'workspace_id'])
                ->on('workspace_entitlements')
                ->restrictOnDelete();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_workspace_entitlement_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'workspace_entitlement_events are append-only'
                    USING ERRCODE = '55000';
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workspace_entitlement_events_prevent_mutation
            BEFORE UPDATE OR DELETE ON workspace_entitlement_events
            FOR EACH ROW
            EXECUTE FUNCTION prevent_workspace_entitlement_event_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER workspace_entitlement_events_prevent_truncate
            BEFORE TRUNCATE ON workspace_entitlement_events
            FOR EACH STATEMENT
            EXECUTE FUNCTION prevent_workspace_entitlement_event_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS workspace_entitlement_events_prevent_truncate ON workspace_entitlement_events');
        DB::statement('DROP TRIGGER IF EXISTS workspace_entitlement_events_prevent_mutation ON workspace_entitlement_events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_workspace_entitlement_event_mutation()');

        Schema::dropIfExists('workspace_entitlement_events');
    }
};
