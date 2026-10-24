<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->text('physical_examination')->nullable()->after('current_illness');
            $table->text('review_of_systems')->nullable()->after('physical_examination');
            $table->tinyInteger('pain_scale')->nullable()->after('review_of_systems');
            $table->date('follow_up_date')->nullable()->after('treatment');
            $table->text('follow_up_notes')->nullable()->after('follow_up_date');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn([
                'physical_examination',
                'review_of_systems',
                'pain_scale',
                'follow_up_date',
                'follow_up_notes',
            ]);
        });
    }
};
