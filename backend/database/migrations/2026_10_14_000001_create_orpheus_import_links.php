<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('import_source', 20)->nullable();
        });

        Schema::create('orpheus_import_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 20);
            $table->string('source_id', 80);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['workspace_id', 'entity_type', 'source_id'], 'orpheus_link_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orpheus_import_links');
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('import_source');
        });
    }
};
