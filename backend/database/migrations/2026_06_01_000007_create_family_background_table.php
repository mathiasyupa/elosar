<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creates the 'family_background' table — patient family medical history.
     *
     * Why a relational table instead of JSONB like personal history?
     * Because this data needs to be filterable and reportable:
     * "Give me every patient with a family history of diabetes"
     * → Only possible with real columns, not JSON fields.
     *
     * Personal history (allergies, surgeries) varies per specialty and is only
     * read by the doctor during consultation → JSONB on patients.
     * Family history is structured and comparable → its own table.
     */
    public function up(): void
    {
        Schema::create('family_background', function (Blueprint $table) {
            $table->id();

            // Patient this history belongs to
            $table->foreignId('patient_id')
                  ->constrained('patients')
                  ->onDelete('cascade');

            // Disease or condition of the relative (ideally ICD-10 coded, or free text)
            // E.g. "Type 2 diabetes", "Hypertension", "Breast cancer"
            $table->string('disease');

            // Affected relative
            // E.g. 'father', 'mother', 'maternal_grandfather', 'brother'
            $table->string('relative_type');

            // Additional notes from the doctor (optional)
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_background');
    }
};
