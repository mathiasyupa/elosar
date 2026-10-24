<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links the 'doctors' table with 'users' (authentication account).
     *
     * System login is done through the User model (Breeze + Filament).
     * Each User who is a doctor points to their clinical record in doctors via
     * this user_id. From the authenticated session we can resolve the current
     * doctor ($user->doctor) and, from there, their personal workspace.
     *
     * Nullable because some Users are not doctors (e.g. system admin)
     * and unique because a User maps to at most one Doctor.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->foreignId('user_id')
                  ->nullable()
                  ->unique()
                  ->after('id')
                  ->constrained('users')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
