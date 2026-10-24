<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a doctor accepted Elosar's terms, and WHICH version.
 *
 * Without this there is no evidence anyone agreed to anything: the documents
 * could be published and a doctor could still say, correctly, that they were
 * never shown them. The version matters as much as the timestamp — "they
 * accepted the terms" is worthless if nobody can say which text that was.
 *
 * Distinct from the patient consent already stored on `patients`: different
 * document, different parties. A doctor accepting Elosar's terms says nothing
 * about a patient consenting to their clinic's data handling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });

        // Accounts that predate the requirement stay NULL on purpose. Nothing is
        // backfilled: claiming someone agreed to a document that did not exist
        // when they signed up would fabricate the exact evidence this column
        // exists to hold. The NULL is the honest record, and it is what will
        // trigger asking them to accept once the documents are published.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
