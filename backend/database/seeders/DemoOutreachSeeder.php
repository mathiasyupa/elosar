<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;
use Illuminate\Database\Seeder;

/**
 * Backfills the two patient call lists (Pacientes → Reactivar / Seguimiento)
 * on the demo clinic: lapsed histories, ordered follow-ups nobody booked, and
 * the billing that ranks the re-engage list by patient value.
 *
 * Kept SEPARATE from the main demo seeders on purpose. The deploy only runs
 * `migrate`, never `db:seed`, so a production instance that was seeded before
 * these features existed has the demo clinic but empty lists. This seeder is
 * safe to run standalone on every deploy because it:
 *   - only ever touches the existing demo clinic (no-op if it isn't there, so a
 *     real clinic is never modified),
 *   - creates NO login accounts or passwords (unlike the full demo seed),
 *   - is idempotent — the underlying methods guard themselves, so re-running
 *     is a no-op.
 *
 * It reuses the exact methods the fresh seed uses, so the demo data can never
 * drift between a from-scratch seed and a production backfill.
 */
class DemoOutreachSeeder extends Seeder
{
    public function run(): void
    {
        app(ActiveWorkspace::class)->runUnguarded(fn () => $this->seed());
    }

    private function seed(): void
    {
        $clinic = Workspace::where('type', 'clinic')
            ->where('name', ClinicalDataSeeder::CLINIC_NAME)
            ->first();

        // No demo clinic → nothing to backfill. This is the guard that keeps a
        // real production database untouched.
        if (! $clinic) {
            $this->command?->info('DemoOutreachSeeder: no demo clinic found, nothing to do.');

            return;
        }

        $director = Doctor::find($clinic->owner_doctor_id);
        // The second demo doctor owns the "employed doctor" follow-ups; fall
        // back to the director so a partial demo still populates the lists.
        $medico2 = Doctor::whereHas('user', fn ($q) => $q->where('email', 'medico@clinica.com'))->first()
            ?? $director;

        if (! $director) {
            $this->command?->warn('DemoOutreachSeeder skipped: demo clinic has no owner doctor.');

            return;
        }

        $patients = Patient::where('workspace_id', $clinic->id)->orderBy('id')->get();

        if ($patients->isEmpty()) {
            $this->command?->warn('DemoOutreachSeeder skipped: demo clinic has no patients.');

            return;
        }

        $services = MedicalService::where('workspace_id', $clinic->id)->get();

        // Delegate to the same methods the fresh seed uses.
        $clinical = new ClinicalDataSeeder();
        $clinical->setContainer($this->container)->setCommand($this->command);
        $clinical->seedLapsedHistories($patients, $director, $medico2);
        $clinical->seedPendingFollowUps($patients);

        if ($services->isNotEmpty()) {
            $demo = new DemoDataSeeder();
            $demo->setContainer($this->container)->setCommand($this->command);
            $demo->seedOutreachBilling($clinic, $director, $patients, $services);
        }

        $this->command?->info('DemoOutreachSeeder: re-engage and follow-up lists backfilled.');
    }
}
