<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-level account lock (set only from the /superadmin panel). Blocks
     * both Filament panels (User::canAccessPanel) and API login/token issuance
     * (AuthController::login) without deleting any data.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('account_type');
            $table->string('suspended_reason')->nullable()->after('suspended_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });
    }
};
