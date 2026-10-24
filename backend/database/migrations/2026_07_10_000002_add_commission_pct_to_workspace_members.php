<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doctor revenue-share (commission) per clinic membership.
 *
 * A clinic pays each attending doctor a percentage of what it collects for that
 * doctor's attentions; the clinic keeps the rest. This percentage is agreed per
 * doctor per clinic, so it lives on the membership (workspace_members) alongside
 * role / is_admin. Nullable: no split defined yet (the doctor's share is treated
 * as unset in the settlement report). Only meaningful for doctor members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->decimal('commission_pct', 5, 2)->nullable()->after('is_admin')
                ->comment('Doctor revenue-share %, 0-100. Null = no split agreed.');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_members', function (Blueprint $table): void {
            $table->dropColumn('commission_pct');
        });
    }
};
