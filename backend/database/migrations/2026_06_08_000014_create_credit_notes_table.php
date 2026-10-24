<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();

            $table->foreignId('invoice_id')
                ->constrained('invoices')
                ->cascadeOnDelete();

            $table->string('credit_note_number', 20)
                ->comment('Sequential number, e.g. NC-0001');

            $table->date('issued_at');
            $table->text('reason')->comment('Reason for the credit note');
            $table->decimal('amount', 10, 2)->comment('Credited or refunded amount');

            $table->string('status', 20)->default('issued')
                ->comment('Status: issued|applied');

            // Doctor who generated the note (null when auto-generated)
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('doctors')
                ->nullOnDelete();

            $table->timestamps();

            // Credit note numbers are unique within a workspace
            $table->unique(['workspace_id', 'credit_note_number']);
            $table->index('workspace_id');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
