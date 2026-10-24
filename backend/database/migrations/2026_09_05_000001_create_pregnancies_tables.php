<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pregnancy as a first-class entity, not a block on the patient card.
 *
 * The systems Ecuadorian clinics already use keep ONE "current gestation" block
 * on the patient's file, overwritten the next time she conceives. That loses the
 * obstetric history precisely where it matters, and it makes gestational age
 * ambiguous: age since when? Here a pregnancy is a row with a lifecycle, and the
 * prenatal visits hang off it.
 *
 * `edd_date` / `edd_protocol` are DERIVED — only `GestationalAge` writes them.
 * The due date is a dating DECISION, not a display value: it must be stable,
 * sortable and indexable (the report orders by it), and a future change of
 * protocol must not silently re-date pregnancies that were already lived. Same
 * argument as `record_vitals.score_mama_protocol`. Today's gestational age, by
 * contrast, is never stored — it would be a lie by tomorrow morning.
 *
 * `pregnancy_controls` deliberately carries no `workspace_id`: it is reached
 * through `medical_records`, which is already workspace-scoped, exactly like
 * `record_vitals`. Weight and blood pressure are NOT duplicated here — they live
 * in `record_vitals`, where the Score MAMÁ is already computed, so the weight
 * curve and the fundal-height curve come from one reading and cannot disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pregnancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default('active')
                ->comment('active|completed|lost|unknown — see Pregnancy::STATUSES');

            // Dating inputs. Both may coexist; `dating_source` says which one rules.
            $table->date('lmp_date')->nullable()->comment('FUM — last menstrual period');
            $table->boolean('lmp_is_reliable')->nullable();
            $table->date('ultrasound_date')->nullable()->comment('date the dating scan was taken');
            $table->unsignedTinyInteger('ultrasound_weeks')->nullable();
            $table->unsignedTinyInteger('ultrasound_days')->nullable();
            $table->string('dating_source', 20)->default('lmp')
                ->comment('lmp|ultrasound|manual — the clinician decides, the server never re-dates on its own');

            // Derived server-side by GestationalAge; never accepted from a client.
            $table->date('edd_date')->nullable()->comment('FPP — derived');
            $table->string('edd_protocol', 20)->nullable()->comment('stamped per row so a revision cannot re-date history');

            $table->unsignedTinyInteger('fetus_count')->default(1);

            // Free text until the MSP scored risk table can be verified —
            // see knowledge/reference/RIESGO_OBSTETRICO.md. Better an honest note
            // than a number the product pretends to have computed.
            $table->text('risk_notes')->nullable();

            $table->string('outcome', 30)->nullable()->comment('see Pregnancy::OUTCOMES');
            $table->date('outcome_date')->nullable();
            $table->text('outcome_notes')->nullable();

            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'patient_id']);
            $table->index(['workspace_id', 'status', 'edd_date']);
        });

        // One active pregnancy per patient, enforced by the database and not by a
        // controller: two tabs open must not be able to create two. The
        // `deleted_at IS NULL` is NOT optional — without it, soft-deleting a
        // pregnancy and opening a new one fails with a unique violation, and that
        // is the kind of thing that is discovered in production.
        DB::statement(
            'CREATE UNIQUE INDEX pregnancies_one_active_per_patient '
            . 'ON pregnancies (patient_id) '
            . "WHERE status = 'active' AND deleted_at IS NULL"
        );

        Schema::create('pregnancy_controls', function (Blueprint $table) {
            $table->id();
            // One control per consultation. No workspace_id: reached through the
            // scoped medical record, like record_vitals.
            $table->foreignId('medical_record_id')->unique()->constrained()->cascadeOnDelete();

            // Frozen the day it was taken: this is the age the finding was read
            // against. The current age is recomputed from the pregnancy's dating.
            $table->integer('gestational_age_days')->nullable();
            $table->string('dating_source', 20)->nullable();
            $table->string('dating_protocol', 20)->nullable();

            $table->decimal('fundal_height_cm', 4, 1)->nullable()->comment('AFU');
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable()->comment('FCF/LCF, lpm');
            $table->string('fetal_presentation', 20)->nullable();
            $table->boolean('fetal_movements')->nullable();
            $table->string('edema', 20)->nullable();
            $table->string('proteinuria', 10)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('pregnancy_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pregnancy_id')->constrained()->cascadeOnDelete();
            // Catalogue lives in the service, sourced from the MSP prenatal GPC —
            // see knowledge/reference/CARNE_PERINATAL.md. A row per screening
            // instead of ~30 fixed columns: a new one needs no migration, and each
            // carries its own date and author.
            $table->string('code', 40);
            $table->string('result', 120)->nullable();
            $table->date('result_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pregnancy_id', 'code']);
        });

        Schema::table('medical_records', function (Blueprint $table) {
            // The only consultation<->pregnancy association, so there is one source.
            // restrictOnDelete: a pregnancy with visits is closed, never deleted.
            $table->foreignId('pregnancy_id')->nullable()->after('patient_id')
                ->constrained()->restrictOnDelete();
            $table->index(['pregnancy_id', 'created_at']);
        });

        DB::unprepared(
            'CREATE TRIGGER pregnancies_audit '
            . 'AFTER INSERT OR UPDATE OR DELETE ON pregnancies '
            . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pregnancies_audit ON pregnancies;');

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropForeign(['pregnancy_id']);
            $table->dropIndex(['pregnancy_id', 'created_at']);
            $table->dropColumn('pregnancy_id');
        });

        Schema::dropIfExists('pregnancy_screenings');
        Schema::dropIfExists('pregnancy_controls');
        Schema::dropIfExists('pregnancies');
    }
};
