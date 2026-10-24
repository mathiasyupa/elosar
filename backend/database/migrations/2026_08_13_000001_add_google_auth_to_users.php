<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Continue with Google" identity on the user account.
     *
     * `password` becomes nullable because an account created through Google has
     * no password at all, and storing a random hash to satisfy a NOT NULL would
     * be a lie the rest of the code would have to keep believing. Nothing needs
     * to change in AuthController::login(): it already resolves the hash as
     * `$user?->password ?? DUMMY_PASSWORD_HASH`, so a null password fails the
     * bcrypt comparison and returns the same generic error as an unknown email —
     * no crash, and no signal that the account exists. Such a user can still
     * obtain a password through the ordinary "forgot password" flow.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 64)->nullable()->unique()->after('account_type');
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('google_id');
        });

        // Deliberately NOT restoring the NOT NULL on `password`: by the time this
        // runs there may be Google accounts with a null one, and the rollback
        // would fail on them.
    }
};
