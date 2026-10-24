<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Street address of the patient, which replaces `city` on the intake form:
     * for a consulting room, where someone actually lives is the operational
     * fact — home visits, deliveries, an invoice that needs a real address —
     * while the city alone rarely answers anything.
     *
     * `city` is deliberately kept, not dropped. Existing patients carry a value
     * in it and the "patients by city" report reads it; removing the column
     * would destroy that history to save nothing. It simply stops being asked
     * for, so it freezes rather than grows.
     *
     * Nullable because every patient already in the table predates this field,
     * and a NOT NULL would have to invent an address for each of them.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }
};
