<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // NOTE: We do NOT use WithoutModelEvents here. Model events must fire
    // during seeding so each Doctor automatically creates its personal workspace
    // (Doctor::created event). Suppressing them broke that creation.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Catalogs and roles are safe everywhere: a fresh production database
        // needs them to work at all.
        $this->call([
            SpecialtySeeder::class,
            Cie10Seeder::class,
            MedicationSeeder::class,
            StudySeeder::class,
            VaccineSeeder::class,
        ]);

        // Demo data is a different matter: it creates a fictional clinic, 20
        // invented patients and SEVEN LOGIN ACCOUNTS WHOSE PASSWORD IS
        // "password" (test@example.com = clinic director, plus the SaaS owner).
        // `db:seed --force` on a production box would hand out those keys, so
        // it is opt-in there via SEED_DEMO_DATA=true.
        if ($this->shouldSeedDemoData()) {
            $this->call([
                ClinicalDataSeeder::class,
                DemoDataSeeder::class,
                // Populates the re-engage / follow-up call lists. Safe to run
                // anywhere (demo-clinic-only, no accounts), so the deploy
                // entrypoint also runs it to backfill an existing production
                // demo clinic that predates these features.
                DemoOutreachSeeder::class,
            ]);
        } else {
            $this->command->warn(
                'Demo data skipped (APP_ENV=production). Set SEED_DEMO_DATA=true to seed the demo clinic anyway.'
            );
        }

        $this->call(ShieldSeeder::class);
    }

    /**
     * Demo data seeds everywhere except production, where it must be asked for
     * explicitly.
     */
    private function shouldSeedDemoData(): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN);
    }
}
