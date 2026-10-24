<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level clinic/workspace lock (set only from the /superadmin
     * panel). ActiveWorkspace::available() excludes suspended workspaces, which
     * blocks every member from selecting or operating in it without touching
     * each controller individually.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('subscription_status');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });
    }
};
