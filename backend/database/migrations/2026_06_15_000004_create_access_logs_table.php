<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read-access log for sensitive clinical data.
 *
 * For medical data, recording WHO VIEWED a record is as important as recording
 * who changed it — and a write-trigger cannot capture reads. This table is
 * written from the application layer (App\Support\AccessLogger) when a patient
 * file or medical record is viewed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('subject_type', 64); // e.g. patient, medical_record
            $table->unsignedBigInteger('subject_id');
            $table->string('action', 20)->default('view');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
            $table->index('workspace_id');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_logs');
    }
};
