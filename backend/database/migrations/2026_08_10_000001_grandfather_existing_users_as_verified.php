<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marks every account that exists today as verified.
 *
 * User now implements MustVerifyEmail and API login refuses an unverified
 * address. Without this, the rule would apply retroactively and lock out every
 * account created before it existed — including the doctors already using
 * Elosar and the only platform_admin — with no way back in, because the way back
 * in is a login.
 *
 * A rule cannot punish people for not following it before it existed. New
 * self-registrations from here on do have to verify.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Deliberately empty. Reversing this would un-verify accounts that were
        // never asked to verify, locking them out — the exact harm the migration
        // exists to prevent. Nothing here is worth "restoring".
    }
};
