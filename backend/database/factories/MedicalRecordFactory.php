<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    protected $model = MedicalRecord::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();

        return [
            'workspace_id'             => $workspace->id,
            'patient_id'               => Patient::factory()->state(['workspace_id' => $workspace->id]),
            'created_by'               => Doctor::factory(),
            'reason_for_consultation'  => fake('es_ES')->sentence(8),
            'current_illness'          => fake('es_ES')->paragraph(2),
            'diagnosis'                => fake('es_ES')->sentence(5),
            'treatment'                => fake('es_ES')->paragraph(1),
        ];
    }

    /**
     * Creates the record with workspace, patient and doctor already linked.
     * Useful when a test needs full control over the relations.
     */
    public function forWorkspace(Workspace $workspace, ?Patient $patient = null, ?Doctor $doctor = null): static
    {
        return $this->state([
            'workspace_id' => $workspace->id,
            'patient_id'   => ($patient ?? Patient::factory()->state(['workspace_id' => $workspace->id]))->id ?? Patient::factory()->state(['workspace_id' => $workspace->id]),
            'created_by'   => $doctor?->id ?? Doctor::factory(),
        ]);
    }
}
