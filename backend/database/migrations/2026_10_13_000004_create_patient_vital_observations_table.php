<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_vital_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('head_circumference_cm', 5, 2)->nullable();
            $table->string('length_height_measurement_position', 24)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->unsignedSmallInteger('blood_pressure_sys')->nullable();
            $table->unsignedSmallInteger('blood_pressure_dia')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->decimal('oxygen_saturation', 4, 1)->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->string('oxygen_therapy', 24)->nullable();
            $table->string('consciousness_level', 24)->nullable();
            $table->decimal('urine_output_ml_h', 6, 1)->nullable();
            $table->boolean('urine_output_not_quantifiable')->nullable();
            $table->unsignedSmallInteger('score_mama_total')->nullable();
            $table->string('score_mama_level', 24)->nullable();
            $table->string('score_mama_protocol', 24)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'patient_id', 'recorded_at'], 'patient_vitals_workspace_patient_recorded_index');
        });

        DB::unprepared('CREATE TRIGGER patient_vital_observations_audit AFTER INSERT OR UPDATE OR DELETE ON patient_vital_observations FOR EACH ROW EXECUTE FUNCTION audit_row_change();');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS patient_vital_observations_audit ON patient_vital_observations;');
        Schema::dropIfExists('patient_vital_observations');
    }
};
