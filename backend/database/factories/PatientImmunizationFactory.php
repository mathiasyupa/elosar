<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientImmunization;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientImmunization>
 */
class PatientImmunizationFactory extends Factory
{
    protected $model = PatientImmunization::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id'    => $workspace->id,
            'patient_id'      => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'vaccine_id'      => null,
            'vaccine_name'    => 'BCG',
            'dose_label'      => 'dose_1',
            'administered_at' => now()->toDateString(),
            'lot'             => null,
            'notes'           => null,
            'reminder_at'     => null,
        ];
    }
}
