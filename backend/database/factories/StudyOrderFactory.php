<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\StudyOrder;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyOrder>
 */
class StudyOrderFactory extends Factory
{
    protected $model = StudyOrder::class;

    public function definition(): array
    {
        $workspace = Workspace::factory()->create();
        $patient   = Patient::factory()->state(['workspace_id' => $workspace->id]);

        return [
            'workspace_id'       => $workspace->id,
            'medical_record_id'  => MedicalRecord::factory()->state(['workspace_id' => $workspace->id]),
            'patient_id'         => $patient,
            'created_by'         => Doctor::factory(),
            'type'               => StudyOrder::TYPE_LABORATORY,
            'priority'           => 'routine',
            'clinical_info'      => fake('es_ES')->sentence(5),
            'notes'              => null,
            'to_be_performed_at' => null,
        ];
    }

    public function imaging(): static
    {
        return $this->state(['type' => StudyOrder::TYPE_IMAGING]);
    }
}
