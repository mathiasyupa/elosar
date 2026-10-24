<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp reminders are sent manually via click-to-chat (wa.me), tracked
 * separately from the email reminder (`reminder_sent_at`) so the two channels
 * never interfere — the daily email job keeps deduping on its own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('whatsapp_reminded_at')->nullable()->after('reminder_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('whatsapp_reminded_at');
        });
    }
};
