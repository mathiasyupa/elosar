<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Support\ActiveWorkspace;
use Illuminate\Console\Command;

/**
 * One-off, idempotent data fix: the early demo seed wrote the free-text
 * `personal_background` fields (allergies / surgeries / current_medication) in
 * English. This translates ONLY the exact known English seed values to Spanish
 * so a Spanish-only user never sees them, and leaves any other value untouched
 * (so real, doctor-entered text is never modified). Safe to re-run.
 *
 * Enum-ish values (gender, civil_status, appointment type) are intentionally NOT
 * changed here: the DB keeps them in English by convention and the frontend maps
 * them to Spanish at display time.
 *
 * Usage: php artisan patients:translate-background [--dry-run] [--force]
 */
class TranslatePatientBackgroundCommand extends Command
{
    protected $signature = 'patients:translate-background {--dry-run : List changes without saving} {--force : Skip confirmation}';

    protected $description = 'Translate legacy English personal_background free-text values (allergies/surgeries/medication) to Spanish';

    /** Exact English seed value → Spanish, per JSON key. Only exact matches are replaced. */
    private const MAP = [
        'allergies' => [
            'None known'  => 'Ninguna conocida',
            'Penicillin'  => 'Penicilina',
            'Dust / mites' => 'Polvo / ácaros',
            'Shellfish'   => 'Mariscos',
        ],
        'surgeries' => [
            'None'             => 'Ninguna',
            'Appendectomy'     => 'Apendicectomía',
            'Cesarean section' => 'Cesárea',
        ],
        'current_medication' => [
            'None'           => 'Ninguna',
            'Losartan 50mg'  => 'Losartán 50mg',
            'Metformin 850mg' => 'Metformina 850mg',
        ],
    ];

    public function handle(ActiveWorkspace $activeWorkspace): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm(
            'Translate legacy English personal_background values to Spanish across all workspaces?'
        )) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // CLI has no active tenant; iterate across all workspaces explicitly.
        $changed = $activeWorkspace->runUnguarded(function () use ($dryRun): int {
            $count = 0;

            Patient::query()->whereNotNull('personal_background')->chunkById(200, function ($patients) use (&$count, $dryRun) {
                foreach ($patients as $patient) {
                    $bg = $patient->personal_background;
                    if (! is_array($bg)) {
                        continue;
                    }

                    $dirty = false;
                    foreach (self::MAP as $key => $translations) {
                        $value = $bg[$key] ?? null;
                        if (is_string($value) && isset($translations[$value])) {
                            $this->line("  #{$patient->id} {$key}: \"{$value}\" → \"{$translations[$value]}\"");
                            $bg[$key] = $translations[$value];
                            $dirty = true;
                        }
                    }

                    if ($dirty) {
                        $count++;
                        if (! $dryRun) {
                            $patient->personal_background = $bg;
                            $patient->save();
                        }
                    }
                }
            });

            return $count;
        });

        $verb = $dryRun ? 'would be updated' : 'updated';
        $this->info("{$changed} patient(s) {$verb}.");

        return self::SUCCESS;
    }
}
