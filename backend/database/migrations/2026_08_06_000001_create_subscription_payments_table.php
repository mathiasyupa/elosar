<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a clinic paid Elosar for its subscription.
 *
 * Deliberately separate from the clinical ledger: `invoices`, `payments` and
 * everything SRI record money a *patient* pays a clinic. This records money a
 * clinic pays us, which has a different payer, a different fiscal emitter and
 * no relationship to a patient's care.
 *
 * `period_start` / `period_end` are what make the table answer "who owes me".
 * Without the covered period there is only a list of amounts, and no way to
 * tell an account that is up to date from one that stopped paying in March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method', 16);
            $table->date('paid_at');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // "Paid until" is max(period_end) per workspace, read on every row
            // of the subscriptions list.
            $table->index(['workspace_id', 'period_end']);
            $table->index('paid_at');
        });

        DB::statement("ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_method_check CHECK (method IN ('transfer', 'cash', 'card', 'other'))");
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_amount_check CHECK (amount >= 0)');
        DB::statement('ALTER TABLE subscription_payments ADD CONSTRAINT subscription_payments_period_check CHECK (period_end >= period_start)');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
