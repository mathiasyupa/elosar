<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Email + role invitations to a clinic. The owner issues a token; the
     * invitee redeems it during staff registration. Distinct from the clinic's
     * generic invite_code (used by doctors to self-join) because it pins a role
     * and a target email. NOT workspace-scoped at the model level — looked up by
     * token, validated against the owner explicitly.
     */
    public function up(): void
    {
        Schema::create('clinic_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('email');
            $table->string('role'); // nurse | receptionist | billing | admin
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_invites');
    }
};
