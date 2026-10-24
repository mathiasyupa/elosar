<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->foreignId('appointment_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('appointments')
                ->nullOnDelete()
                ->comment('Appointment that originated this medical record (optional)');

            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });
    }
};
