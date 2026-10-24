<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each doctor taught Elo (ADR-017, part 2). Rules are per doctor AND
 * per workspace: visible, editable and deletable, never a hidden memory.
 * Agent switches live beside them; no row means the agent is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elo_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 30);
            $table->string('text', 300);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'agent']);
        });

        Schema::create('elo_agent_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent', 30);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id', 'agent']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elo_agent_settings');
        Schema::dropIfExists('elo_rules');
    }
};
