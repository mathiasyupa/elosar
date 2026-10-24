<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOTP multi-factor authentication for Filament panels.
 *
 * Both columns hold Laravel-encrypted payloads (see the `encrypted` casts on
 * App\Models\User), so they are `text` rather than sized strings — ciphertext is
 * far longer than the 32-character secret it protects. A leaked database dump
 * therefore does not hand over working second factors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
