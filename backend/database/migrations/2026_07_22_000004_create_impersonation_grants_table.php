<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time, short-lived exchange codes for the /superadmin "Impersonate"
     * action. The Filament action issues a code (not a live bearer token) so
     * the ?code= in the magic link is worthless after first use or after 5
     * minutes — browser history / access logs never carry a reusable
     * full-access credential.
     */
    public function up(): void
    {
        Schema::create('impersonation_grants', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->foreignId('target_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_grants');
    }
};
