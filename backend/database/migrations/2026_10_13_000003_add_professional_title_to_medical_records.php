<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $table->string('professional_title', 120)->nullable()->after('consultation_type');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $table->dropColumn('professional_title');
        });
    }
};
