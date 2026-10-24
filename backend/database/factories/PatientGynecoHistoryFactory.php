<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientGynecoHistory;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientGynecoHistory>
 */
class PatientGynecoHistoryFactory extends Factory
{
    protected $model = PatientGynecoHistory::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id'         => $workspace->id,
            'patient_id'           => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'menarche_age'         => fake()->numberBetween(10, 16),
            'cycle_length_days'    => 28,
            'cycle_duration_days'  => 5,
            'cycle_is_regular'     => true,
            'sexual_debut_age'     => fake()->numberBetween(16, 24),
            'contraceptive_method' => 'none',
            'gestations'           => 2,
            'term_births'          => 1,
            'cesareans'            => 1,
            'abortions'            => 0,
            'living_children'      => 2,
        ];
    }

    public function forPatient(Patient $patient): static
    {
        return $this->state([
            'workspace_id' => $patient->workspace_id,
            'patient_id'   => $patient->id,
        ]);
    }
}
