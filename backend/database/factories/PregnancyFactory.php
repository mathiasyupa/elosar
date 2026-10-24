<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\Workspace;
use App\Services\Obstetrics\GestationalAge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pregnancy>
 */
class PregnancyFactory extends Factory
{
    protected $model = Pregnancy::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        // Around 20 weeks along: far enough in to have a plausible history, far
        // enough from term that a test adding weeks does not accidentally
        // produce a post-term pregnancy.
        $lmp = now()->subDays(140)->toDateString();

        return [
            'workspace_id'  => $workspace->id,
            'patient_id'    => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'status'        => 'active',
            'lmp_date'      => $lmp,
            'dating_source' => 'lmp',
            'fetus_count'   => 1,
            // Mirrors what the controller does: the due date is never invented by
            // a caller, it comes from the service.
            ...GestationalAge::columnsFor(['lmp_date' => $lmp, 'dating_source' => 'lmp']),
        ];
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state([
            'workspace_id' => $patient->workspace_id,
            'patient_id'   => $patient->id,
        ]);
    }

    public function completed(string $outcome = 'term_birth'): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => 'completed',
            'outcome'      => $outcome,
            'outcome_date' => now()->toDateString(),
        ]);
    }

    /** Dated by an early scan instead of by the last period. */
    public function datedByUltrasound(int $weeks = 12, int $days = 0): static
    {
        return $this->state(function (array $attributes) use ($weeks, $days) {
            $takenOn = now()->subDays(56)->toDateString();

            $dating = [
                'lmp_date'         => $attributes['lmp_date'] ?? null,
                'ultrasound_date'  => $takenOn,
                'ultrasound_weeks' => $weeks,
                'ultrasound_days'  => $days,
                'dating_source'    => 'ultrasound',
            ];

            return [...$dating, ...GestationalAge::columnsFor($dating)];
        });
    }
}
