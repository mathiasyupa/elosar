<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 20);
            $table->date('issued_at');
            $table->date('due_at')->nullable();
            $table->string('status', 20)->default('issued')->comment('draft|issued|partial|paid|cancelled|void');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('iva_amount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'invoice_number']);
            $table->index('workspace_id');
            $table->index('patient_id');
            $table->index('status');
            $table->index('issued_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
