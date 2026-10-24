<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured SRI failure info, so the emitter can be told WHY a voucher was
 * rejected instead of just THAT it was.
 *
 * `sri_last_error` stays as-is: it keeps feeding the logs and the Filament
 * panel, and it is never exposed over the API because it may carry PHP
 * exception text. `sri_error` is the curated, safe-to-show version.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->jsonb('sri_error')->nullable()->after('sri_last_error')
                    ->comment('Curated failure info safe to expose: {source, code, message, info} or {source, category}');
                $t->timestamp('sri_last_attempt_at')->nullable()->after('sri_error')
                    ->comment('When the voucher was last submitted; powers the "stuck" detection');
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['sri_error', 'sri_last_attempt_at']);
            });
        }
    }
};
