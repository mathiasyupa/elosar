<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_vitals', function (Blueprint $table) {
            $table->decimal('head_circumference_cm', 5, 2)->nullable()->after('height_cm');
            $table->string('length_height_measurement_position', 24)->nullable()->after('head_circumference_cm')
                ->comment('recumbent_length | standing_height');
        });

        Schema::create('record_growth_assessments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medical_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('record_vital_id')->nullable()->constrained('record_vitals')->nullOnDelete();
            $table->string('status', 24);
            $table->string('reason', 80)->nullable();
            $table->date('measurement_date')->nullable();
            $table->unsignedSmallInteger('age_days')->nullable();
            $table->string('sex', 10)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('length_height_cm', 6, 2)->nullable();
            $table->decimal('length_height_adjusted_cm', 6, 2)->nullable();
            $table->string('length_height_measurement_position', 24)->nullable();
            $table->decimal('head_circumference_cm', 5, 2)->nullable();
            $table->string('standard', 120);
            $table->string('standard_version', 32);
            $table->string('source_url', 500);
            $table->jsonb('table_checksums');
            $table->jsonb('indicators');
            $table->timestamps();

            $table->unique('record_vital_id');
            $table->index(['workspace_id', 'medical_record_id']);
        });

        DB::unprepared('CREATE TRIGGER record_vitals_audit AFTER INSERT OR UPDATE OR DELETE ON record_vitals FOR EACH ROW EXECUTE FUNCTION audit_row_change();');
        DB::unprepared('CREATE TRIGGER record_growth_assessments_audit AFTER INSERT OR UPDATE OR DELETE ON record_growth_assessments FOR EACH ROW EXECUTE FUNCTION audit_row_change();');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS record_growth_assessments_audit ON record_growth_assessments;');
        DB::unprepared('DROP TRIGGER IF EXISTS record_vitals_audit ON record_vitals;');

        Schema::dropIfExists('record_growth_assessments');

        Schema::table('record_vitals', function (Blueprint $table) {
            $table->dropColumn(['head_circumference_cm', 'length_height_measurement_position']);
        });
    }
};
