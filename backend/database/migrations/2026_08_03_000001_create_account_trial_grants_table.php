<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_trial_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('mode', 40);
            $table->timestampTz('started_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('first_clinic_claimed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE account_trial_grants ADD CONSTRAINT account_trial_grants_mode_check CHECK (mode IN ('doctor_personal_first_clinic', 'clinic_admin_clinic_only'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_trial_grants');
    }
};
