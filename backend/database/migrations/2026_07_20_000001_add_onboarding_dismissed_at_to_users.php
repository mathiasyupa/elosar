<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard shows a "primeros pasos" checklist to a newly registered
 * account. Dismissing it is a per-account preference, not a per-browser one:
 * a doctor who hides it on the laptop should not meet it again on the phone,
 * so it lives here instead of in localStorage.
 *
 * Nullable timestamp rather than a boolean — knowing WHEN someone gave up on
 * the checklist is useful signal, and null reads naturally as "never dismissed".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('onboarding_dismissed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('onboarding_dismissed_at');
        });
    }
};
