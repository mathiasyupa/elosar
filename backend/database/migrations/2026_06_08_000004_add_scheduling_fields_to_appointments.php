<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('type', 30)->default('first_visit')->after('status');
            // first_visit|follow_up|procedure|emergency|telemedicine
            $table->smallInteger('duration_minutes')->default(30)->after('type');
            $table->text('notes')->nullable()->after('duration_minutes');
            $table->string('channel', 20)->nullable()->after('notes');
            // phone|web|in_person|referral
            $table->timestamp('confirmed_at')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'type',
                'duration_minutes',
                'notes',
                'channel',
                'confirmed_at',
            ]);
        });
    }
};
