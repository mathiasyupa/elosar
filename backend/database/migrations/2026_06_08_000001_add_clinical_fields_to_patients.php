<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('blood_type', 5)->nullable()->after('phone');
            $table->string('emergency_contact_name', 255)->nullable()->after('blood_type');
            $table->string('emergency_contact_phone', 50)->nullable()->after('emergency_contact_name');
            $table->string('photo_path', 500)->nullable()->after('emergency_contact_phone');
            $table->string('insurance_provider', 255)->nullable()->after('photo_path');
            $table->string('insurance_policy_number', 100)->nullable()->after('insurance_provider');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'blood_type',
                'emergency_contact_name',
                'emergency_contact_phone',
                'photo_path',
                'insurance_provider',
                'insurance_policy_number',
            ]);
        });
    }
};
