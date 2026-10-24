<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LOPDP (Ecuador) consent tracking for patients.
 *
 * Records the lawful basis for processing sensitive health data: when the
 * patient gave informed consent, which version of the consent text they
 * accepted, and which user captured it. `consent_marketing` is a separate,
 * revocable opt-in for non-clinical communications (birthday/re-engagement
 * emails, WhatsApp), which must NOT be sent without it.
 *
 * All columns are nullable so existing records remain valid; consent can be
 * back-filled through the normal patient edit flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->timestamp('data_consent_at')->nullable()->after('personal_background');
            $table->string('data_consent_version', 20)->nullable()->after('data_consent_at');
            $table->unsignedBigInteger('data_consent_by_user_id')->nullable()->after('data_consent_version');
            $table->boolean('consent_marketing')->default(false)->after('data_consent_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn([
                'data_consent_at',
                'data_consent_version',
                'data_consent_by_user_id',
                'consent_marketing',
            ]);
        });
    }
};
