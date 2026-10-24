<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orderable studies catalog (Ecuador): laboratory tests and imaging studies the
 * doctor can request during a consultation (HCU "Solicitud de exámenes" / MSP
 * lab + imaging order forms).
 *
 * Global entries (workspace_id = null) ship seeded; workspace-specific entries
 * are clinic-added studies (the "Nuevo" button on the imaging picker). Like the
 * medication catalog this model intentionally does NOT use BelongsToWorkspace —
 * queries must include global + active-workspace rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);            // laboratory | imaging
            $table->string('category', 80)->nullable(); // clinical group: HEMATOLÓGICO, Ecografía, ...
            $table->string('name', 255);
            $table->string('code', 40)->nullable(); // optional internal/lab code
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'type', 'is_active']);
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studies');
    }
};
