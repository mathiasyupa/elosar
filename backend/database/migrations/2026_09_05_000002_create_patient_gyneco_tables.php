<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The gyneco-obstetric history belongs to the PATIENT, not to each consultation.
 *
 * Until now menarche, cycles, gravidity, parity and the last Pap lived inside
 * `medical_records.structured_exam` — a per-consultation JSONB. The doctor
 * retyped them at every check-up and nobody held the patient's actual file.
 *
 * Why a table and not `patients.personal_background`:
 *
 *  1. That JSONB is replaced WHOLESALE on every write (the client sends a literal
 *     of its five known keys and the server does `$patient->update()` over an
 *     `array` cast). Anything else stored there is lost silently, with no error.
 *     A data model must not depend on a client remembering to resend keys it does
 *     not even display.
 *  2. `BoundedStructure` says in so many words that `personal_background` is an
 *     open structure by design — it is prose. Structured clinical facts in this
 *     repo already get a table (allergies, immunisation doses).
 *  3. It gets queried. "Pap overdue" or "gravidity >= 4" over JSONB means `->>`
 *     with casts and no index.
 *
 * Why its own table and not columns on `patients`: that table is already ~30
 * columns and its audit trigger dumps whole-row `to_jsonb(OLD)/to_jsonb(NEW)` —
 * 22 more columns would fatten every audit row written when someone fixes a phone
 * number. And the row only exists once the tab is actually used.
 *
 * No soft deletes: it clashes with the one-row-per-patient unique index and adds
 * nothing — this is a mutable summary, and its history comes from the trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_gyneco_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('menarche_age')->nullable();
            $table->unsignedTinyInteger('cycle_length_days')->nullable()->comment('every how many days');
            $table->unsignedTinyInteger('cycle_duration_days')->nullable()->comment('how many days it lasts');
            $table->boolean('cycle_is_regular')->nullable();
            $table->boolean('dysmenorrhea')->nullable();

            $table->unsignedTinyInteger('sexual_debut_age')->nullable()->comment('IVSA');
            $table->string('contraceptive_method', 40)->nullable();
            $table->string('contraceptive_notes', 255)->nullable();

            $table->date('last_pap_at')->nullable();
            $table->string('last_pap_result', 60)->nullable();
            $table->date('last_mammography_at')->nullable();
            $table->string('last_mammography_result', 60)->nullable();

            // Obstetric formula as REFERRED by the patient. The server never
            // overwrites these with what `pregnancies` holds: they include
            // pregnancies this clinic never saw. The resource returns the derived
            // counts alongside so the UI can show both, labelled.
            $table->unsignedTinyInteger('gestations')->nullable();
            $table->unsignedTinyInteger('term_births')->nullable();
            $table->unsignedTinyInteger('preterm_births')->nullable();
            $table->unsignedTinyInteger('cesareans')->nullable();
            $table->unsignedTinyInteger('abortions')->nullable();
            $table->unsignedTinyInteger('ectopics')->nullable();
            $table->unsignedTinyInteger('stillbirths')->nullable();
            $table->unsignedTinyInteger('living_children')->nullable();

            $table->boolean('is_menopausal')->nullable();
            $table->date('menopause_at')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('workspace_id');
        });

        // Standing patient alerts, shown wherever the patient is on screen.
        // Three anonymous free-text slots (what the competitor offers) cannot say
        // who wrote them, when, or whether they still apply.
        Schema::create('patient_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('text', 255);
            $table->string('severity', 20)->default('warning')->comment('info|warning|critical');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'patient_id', 'resolved_at']);
        });

        DB::unprepared(
            'CREATE TRIGGER patient_gyneco_histories_audit '
            . 'AFTER INSERT OR UPDATE OR DELETE ON patient_gyneco_histories '
            . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS patient_gyneco_histories_audit ON patient_gyneco_histories;');

        Schema::dropIfExists('patient_alerts');
        Schema::dropIfExists('patient_gyneco_histories');
    }
};
