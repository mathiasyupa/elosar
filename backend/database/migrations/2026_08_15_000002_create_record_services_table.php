<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the doctor actually did during a visit ("servicios y procedimientos
 * realizados"), declared while attending and read later by whoever invoices.
 *
 * Until now the closing screen handed caja an appointment and nothing else, so
 * the invoice was rebuilt from memory. That is why in-house laboratory rarely
 * made it onto a bill, and why revenue could not be broken down by service
 * category: invoice lines typed by hand carry no medical_service_id.
 *
 * `medical_service_id` is nullable and `name`/`category` are snapshotted, for
 * the same reason study_order_items snapshot theirs: retitling or deleting a
 * catalogue entry must not rewrite what a closed visit says was done.
 *
 * `unit_price` is nullable on purpose. A doctor in a clinic declares WHAT was
 * done and never prices it — that is the cash desk's job, and the controller
 * enforces it by permission. In a solo consultorio the same person does both,
 * so the price is filled in from the start.
 *
 * Unlike the other MedicalRecord children this one carries `workspace_id` and
 * uses BelongsToWorkspace: it feeds billing, and the isolation of the existing
 * children (which depends entirely on always loading them through the scoped
 * parent) is a documented leak risk that should not be extended to money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medical_record_id')->constrained()->cascadeOnDelete();
            // Catalogue link is optional; the snapshot below is what gets printed.
            $table->foreignId('medical_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->string('category', 40)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            // Null = declared without a price, waiting for the cash desk.
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'medical_record_id']);
            $table->index(['workspace_id', 'category']);
        });

        // Row-level write audit, consistent with the rest of the clinical layer
        // (audit_row_change() is created in an earlier migration).
        DB::unprepared(
            'CREATE TRIGGER record_services_audit '
            . 'AFTER INSERT OR UPDATE OR DELETE ON record_services '
            . 'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS record_services_audit ON record_services;');

        Schema::dropIfExists('record_services');
    }
};
