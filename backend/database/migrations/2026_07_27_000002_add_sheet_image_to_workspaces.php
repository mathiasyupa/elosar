<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Hoja diseñada": the full-page stationery a clinic already prints on.
     *
     * Until now a workspace could upload a banner for the TOP of the page
     * (header_image_path). This is the whole A4 sheet — frame, watermark,
     * footer and all — so the documents come out looking like the paper the
     * clinic already hands to patients. The margins that keep the content
     * inside that design live in prescription_settings (sheet_margins).
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('sheet_image_path')->nullable()->after('header_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('sheet_image_path');
        });
    }
};
