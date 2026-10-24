<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a 'category' column to medical_services so the service catalog can be
     * organized (general, laboratory, imaging, procedure) and, in the future,
     * gate dental procedures (category = 'dental') without speculative schema.
     */
    public function up(): void
    {
        Schema::table('medical_services', function (Blueprint $table) {
            $table->string('category', 30)
                ->default('general')
                ->after('description')
                ->comment('Service category: general|laboratory|imaging|procedure|dental');

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('medical_services', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
