<?php

namespace Database\Factories;

use App\Models\FamilyBackground;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FamilyBackground>
 */
class FamilyBackgroundFactory extends Factory
{
    protected $model = FamilyBackground::class;

    private static array $diseases = [
        'Diabetes Mellitus tipo 2', 'Hipertensión arterial', 'Cardiopatía isquémica',
        'Cáncer de mama', 'Cáncer de colon', 'Asma bronquial', 'Epilepsia',
        'Esquizofrenia', 'Trastorno bipolar', 'Lupus eritematoso sistémico',
    ];

    private static array $relatives = [
        'padre', 'madre', 'hermano', 'hermana', 'abuelo paterno',
        'abuela paterna', 'abuelo materno', 'abuela materna', 'tío', 'tía',
    ];

    public function definition(): array
    {
        return [
            'patient_id'    => Patient::factory(),
            'disease'       => fake()->randomElement(self::$diseases),
            'relative_type' => fake()->randomElement(self::$relatives),
            'notes'         => fake()->optional(0.6)->sentence(),
        ];
    }
}
