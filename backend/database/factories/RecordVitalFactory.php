<?php

namespace Database\Factories;

use App\Models\MedicalRecord;
use App\Models\RecordVital;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordVital>
 */
class RecordVitalFactory extends Factory
{
    protected $model = RecordVital::class;

    public function definition(): array
    {
        $weight = fake()->randomFloat(2, 45, 110);
        $height = fake()->randomFloat(2, 150, 195);
        $heightM = $height / 100;
        $bmi = round($weight / ($heightM * $heightM), 2);

        return [
            'medical_record_id'  => MedicalRecord::factory(),
            'weight_kg'          => $weight,
            'height_cm'          => $height,
            'bmi'                => $bmi,
            'blood_pressure_sys' => fake()->numberBetween(100, 150),
            'blood_pressure_dia' => fake()->numberBetween(60, 95),
            'heart_rate'         => fake()->numberBetween(55, 100),
            'temperature'        => fake()->randomFloat(1, 36.0, 38.5),
            'oxygen_saturation'  => fake()->randomFloat(1, 92.0, 100.0),
            'respiratory_rate'   => fake()->numberBetween(12, 22),
            'recorded_at'        => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
