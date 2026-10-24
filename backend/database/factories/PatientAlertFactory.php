<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientAlert;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAlert>
 */
class PatientAlertFactory extends Factory
{
    protected $model = PatientAlert::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id' => $workspace->id,
            'patient_id'   => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'text'         => fake('es_ES')->sentence(4),
            'severity'     => 'warning',
        ];
    }

    public function resolved(): static
    {
        return $this->state(['resolved_at' => now()]);
    }
}
