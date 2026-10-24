<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support foreign identity documents by tracking the issuing country and
 * widening id_number. Move uniqueness from the number alone to the tuple
 * (document_type, document_country, id_number) so two patients from different
 * countries can share the same numeric document without collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->string('document_country', 2)
                ->nullable()
                ->after('document_type')
                ->comment('ISO 3166-1 alpha-2 country that issued the id_number');
        });

        // Existing patients were created before document_type existed; treat
        // them as Ecuadorian cedula holders so the new composite unique index
        // can be enforced without leaving nullable tuples.
        DB::table('patients')
            ->whereNull('document_type')
            ->update([
                'document_type' => 'cedula',
                'document_country' => 'EC',
            ]);

        Schema::table('patients', function (Blueprint $table): void {
            // Passports and foreign IDs may be longer than 10 characters.
            $table->string('id_number', 30)->change();

            // Drop the old global unique constraint.
            $table->dropUnique('patients_id_number_unique');

            // Composite unique: the same number can exist for different
            // document types or different countries.
            $table->unique(['document_type', 'document_country', 'id_number']);
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropUnique(['document_type', 'document_country', 'id_number']);
            $table->unique('id_number');
            $table->string('id_number', 10)->change();
            $table->dropColumn('document_country');
        });
    }
};
