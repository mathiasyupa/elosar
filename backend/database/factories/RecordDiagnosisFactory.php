<?php

namespace Database\Factories;

use App\Models\Cie10Code;
use App\Models\MedicalRecord;
use App\Models\RecordDiagnosis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordDiagnosis>
 *
 * Pulls a real ICD-10 code from the catalog (cie10_codes) when available so the
 * structured diagnosis stays consistent with the seeded reference data; falls
 * back to a synthetic code only when the catalog has not been seeded.
 */
class RecordDiagnosisFactory extends Factory
{
    protected $model = RecordDiagnosis::class;

    public function definition(): array
    {
        $code = Cie10Code::inRandomOrder()->first();

        return [
            'medical_record_id' => MedicalRecord::factory(),
            'cie10_code'        => $code?->code ?? strtoupper(fake()->bothify('?##.#')),
            'cie10_description' => $code?->description ?? fake('es_ES')->sentence(4),
            'type'              => fake()->randomElement(['primary', 'secondary', 'presumptive']),
        ];
    }

    public function primary(): static
    {
        return $this->state(['type' => 'primary']);
    }

    public function secondary(): static
    {
        return $this->state(['type' => 'secondary']);
    }

    public function presumptive(): static
    {
        return $this->state(['type' => 'presumptive']);
    }
}
