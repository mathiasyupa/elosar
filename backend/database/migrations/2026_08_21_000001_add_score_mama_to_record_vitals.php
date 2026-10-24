<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three Score MAMÁ parameters the table did not carry, plus the score itself.
 *
 * `record_vitals` already held 6 of the 9 the MSP 2025 protocol scores. Oxygen
 * therapy, level of consciousness and urine output were simply not captured
 * anywhere — see `knowledge/reference/SCORE_MAMA.md`.
 *
 * Urine output needs two columns because it has three states, not two: measured
 * (a number), "not quantifiable" (no catheter, outpatient — which the protocol
 * prints in the NORMAL column and scores 0), and not taken at all. A single
 * nullable number could not tell the last two apart, and they score differently.
 *
 * The score is stored rather than derived on read, with the protocol that
 * produced it. A medical record should keep what the clinician saw and acted on;
 * if the MSP publishes a revision with different cut-offs, readings already in
 * the history must not silently re-score themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_vitals', function (Blueprint $table) {
            $table->string('oxygen_therapy', 20)->nullable()->after('oxygen_saturation')
                ->comment('Score MAMA: ambient_air | nasal_cannula | mask_or_higher');
            $table->string('consciousness_level', 20)->nullable()->after('respiratory_rate')
                ->comment('Score MAMA: alert | responds_to_voice | responds_to_pain | unresponsive');
            $table->decimal('urine_output_ml_h', 5, 1)->nullable()->after('consciousness_level')
                ->comment('Score MAMA: measured urine output in mL/h');
            $table->boolean('urine_output_not_quantifiable')->default(false)->after('urine_output_ml_h')
                ->comment('Score MAMA: no catheter / outpatient. Scores 0, and is NOT the same as absent');

            $table->unsignedSmallInteger('score_mama_total')->nullable()->after('urine_output_not_quantifiable')
                ->comment('Computed server-side on write; never accepted from the client');
            $table->string('score_mama_level', 20)->nullable()->after('score_mama_total')
                ->comment('normal | reevaluate | treat_and_refer | emergency');
            $table->string('score_mama_protocol', 20)->nullable()->after('score_mama_level')
                ->comment('Protocol the score was computed with, e.g. MSP-2025');
        });
    }

    public function down(): void
    {
        Schema::table('record_vitals', function (Blueprint $table) {
            $table->dropColumn([
                'oxygen_therapy',
                'consciousness_level',
                'urine_output_ml_h',
                'urine_output_not_quantifiable',
                'score_mama_total',
                'score_mama_level',
                'score_mama_protocol',
            ]);
        });
    }
};
