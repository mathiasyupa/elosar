<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distinguishes the two account kinds the app now supports:
     *  - 'doctor' → linked to a Doctor record + a personal workspace (today's behaviour)
     *  - 'staff'  → receptionist / nurse / billing; NO Doctor, NO personal workspace,
     *               only accesses clinics they are a member of.
     * Existing rows default to 'doctor' so current accounts keep working.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_type')->default('doctor')->after('email');
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('doctor', 'staff'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_type_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
