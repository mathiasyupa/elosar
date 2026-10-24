<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elo's suggestions and what the doctor did with them (accepted, edited,
 * rejected). Audit and adoption metric; no clinical content is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elo_suggestion_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent', 30);
            $table->string('outcome', 20);
            $table->unsignedSmallInteger('items_count')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'agent', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elo_suggestion_events');
    }
};
