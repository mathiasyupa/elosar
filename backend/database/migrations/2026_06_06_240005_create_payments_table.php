<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 20)->default('cash')->comment('cash|transfer|card|other');
            $table->date('payment_date');
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('doctors')->nullOnDelete();
            $table->timestamps();

            $table->index('workspace_id');
            $table->index('payment_date');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
