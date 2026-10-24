<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('plan_code', 16);
            $table->string('state', 16);
            $table->string('source', 24);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('access_ends_at')->nullable();
            $table->unsignedSmallInteger('clinical_seat_limit')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'workspace_id']);
        });

        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_plan_code_check CHECK (plan_code IN ('personal', 'clinic'))");
        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_state_check CHECK (state IN ('trial', 'active', 'read_only'))");
        DB::statement("ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_source_check CHECK (source IN ('signup', 'legacy_review', 'operator'))");
        DB::statement('ALTER TABLE workspace_entitlements ADD CONSTRAINT workspace_entitlements_clinical_seat_limit_check CHECK (clinical_seat_limit IS NULL OR clinical_seat_limit >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_entitlements');
    }
};
