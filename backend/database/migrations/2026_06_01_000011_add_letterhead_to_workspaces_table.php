<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the prescription LETTERHEAD / FORMAT settings to the workspace.
     *
     * Since the workspace represents the doctor's practice or the clinic, this is
     * the right place to store the "frame" the doctor or clinic wants on their
     * prescriptions: logo and header/footer data.
     *
     * A 'prescription_settings' jsonb field keeps it flexible (header, subheader,
     * address, phone, email, footer, color) without creating one column per value.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('subscription_status');
            $table->jsonb('prescription_settings')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'prescription_settings']);
        });
    }
};
