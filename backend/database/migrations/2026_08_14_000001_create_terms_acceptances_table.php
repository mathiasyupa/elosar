<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('version', 50);
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['user_id', 'version']);
        });

        DB::table('users')->whereNotNull('terms_version')->whereNotNull('terms_accepted_at')
            ->orderBy('id')->eachById(function (object $user): void {
                DB::table('terms_acceptances')->insert([
                    'user_id' => $user->id,
                    'version' => $user->terms_version,
                    'accepted_at' => $user->terms_accepted_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
