<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace can present its prescription letterhead in one of two ways:
 *
 *  - "digital": the system draws the header from text fields (name, address,
 *    phone, email) + the optional logo. Editable, no design tools needed.
 *  - "image": the clinic uploads a fully pre-designed letterhead banner (logo +
 *    contact + decorative line baked into a single image), like many Ecuadorian
 *    practices already have. We just place that image at the top.
 *
 * The chosen mode lives in `prescription_settings.header_mode` (jsonb, no schema
 * change); this migration only adds the storage path for the uploaded banner,
 * mirroring `logo_path`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('header_image_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('header_image_path');
        });
    }
};
