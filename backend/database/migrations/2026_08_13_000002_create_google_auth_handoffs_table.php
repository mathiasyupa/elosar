<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short-lived handoff between the Google OAuth callback (a browser redirect,
     * on the API domain) and medico-web (a SPA on another origin).
     *
     * Same reasoning as impersonation_grants: what travels in the ?code= of the
     * redirect is a single-use code, never a live bearer token, so a URL left in
     * browser history or an access log is worthless. The Sanctum token is minted
     * only when the SPA redeems the code.
     *
     * A row with `user_id` means "this Google identity is an existing account,
     * log it in". A row without one means "this Google identity is verified but
     * has no account yet" — it cannot be turned into a session, only presented
     * at sign-up, because Google supplies neither the cédula, phone, city and
     * specialties a Doctor needs nor the acceptance of the terms.
     */
    public function up(): void
    {
        Schema::create('google_auth_handoffs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('google_id', 64);
            $table->string('email');
            $table->string('name')->nullable();
            // Null until sign-up completes: no account exists behind it yet.
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_auth_handoffs');
    }
};
