<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lab / imaging order ("solicitud de exámenes") issued during a consultation.
 *
 * Mirrors the MSP HCU order forms (laboratory + imaging): a header tied to the
 * medical record + patient, a clinical justification (diagnosis/indication), the
 * date the studies should be performed, a free-text "otros exámenes" note and a
 * list of requested studies (items).
 *
 * The order is workspace-scoped (BelongsToWorkspace) and audited like the rest of
 * the clinical layer; its items inherit isolation through the scoped parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medical_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // Issuing doctor (nullable: nurse-started drafts keep it null until the
            // physician signs). FK to doctors, not users.
            $table->foreignId('created_by')->nullable()->constrained('doctors')->nullOnDelete();
            $table->string('type', 20);               // laboratory | imaging
            $table->string('priority', 20)->default('routine'); // routine | urgent
            $table->text('clinical_info')->nullable(); // diagnóstico / indicación clínica
            $table->text('notes')->nullable();         // "Otros exámenes" free text
            $table->date('to_be_performed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'medical_record_id']);
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('study_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_order_id')->constrained()->cascadeOnDelete();
            // Catalog link is optional — the name/category are snapshotted so the
            // printed order stays stable even if the catalog entry changes later.
            $table->foreignId('study_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('category', 80)->nullable();
            $table->string('notes', 255)->nullable(); // e.g. laterality / region for imaging
            $table->timestamps();
            $table->softDeletes();

            $table->index('study_order_id');
        });

        // Row-level write audit, consistent with the rest of the clinical layer
        // (the audit_row_change() function is created in an earlier migration).
        DB::unprepared(
            'CREATE TRIGGER study_orders_audit '
            . 'AFTER INSERT OR UPDATE OR DELETE ON study_orders '
            . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS study_orders_audit ON study_orders;');

        Schema::dropIfExists('study_order_items');
        Schema::dropIfExists('study_orders');
    }
};
