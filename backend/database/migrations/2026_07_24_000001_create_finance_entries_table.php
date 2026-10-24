<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual money movements the invoicing flow cannot know about: operating
     * expenses, income that never went through a patient invoice (renting a
     * consulting room out, for instance) and deductions from a doctor's payout.
     *
     * One table with a `type` discriminator instead of three — same precedent as
     * StudyOrder.type and MedicalService.category. Amounts are always stored
     * positive; the type decides which side of the P&L the row lands on.
     */
    public function up(): void
    {
        Schema::create('finance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('expense'); // expense | income | doctor_adjustment
            $table->string('category', 50)->default('supplies');
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->date('entry_date');
            // Required for doctor_adjustment (a deduction from that doctor's
            // settlement); null for clinic-wide expenses and other income.
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'entry_date', 'type']);
            $table->index(['workspace_id', 'doctor_id']);
        });

        // Row-level write audit — this is money, and it is typed by hand.
        // (the audit_row_change() function is created in an earlier migration).
        DB::unprepared(
            'CREATE TRIGGER finance_entries_audit '
            .'AFTER INSERT OR UPDATE OR DELETE ON finance_entries '
            .'FOR EACH ROW EXECUTE FUNCTION audit_row_change();'
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS finance_entries_audit ON finance_entries;');

        Schema::dropIfExists('finance_entries');
    }
};
