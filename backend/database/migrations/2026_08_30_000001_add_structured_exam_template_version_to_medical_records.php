<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Marks legacy records as the immutable v1 baseline without rewriting JSON. */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('structured_exam_template_version')->default(1)->after('consultation_type');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('structured_exam_template_version');
        });
    }
};
