<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientAllergy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientAllergy>
 *
 * Allergen names are in Spanish (patient-facing clinical content); the
 * allergen_type / severity enums stay in English per the DB convention.
 */
class PatientAllergyFactory extends Factory
{
    protected $model = PatientAllergy::class;

    /**
     * @var array<string,list<string>>
     */
    private static array $allergensByType = [
        'drug'          => ['Penicilina', 'Aspirina', 'Sulfas', 'Ibuprofeno', 'Codeína'],
        'food'          => ['Mariscos', 'Maní', 'Lactosa', 'Huevo', 'Gluten'],
        'environmental' => ['Polen', 'Ácaros del polvo', 'Pelo de gato', 'Picadura de abeja'],
        'other'         => ['Látex', 'Níquel', 'Contraste yodado'],
    ];

    public function definition(): array
    {
        $type = fake()->randomElement(array_keys(self::$allergensByType));

        return [
            'patient_id'    => Patient::factory(),
            'allergen_type' => $type,
            'allergen_name' => fake()->randomElement(self::$allergensByType[$type]),
            'severity'      => fake()->randomElement(['mild', 'moderate', 'severe']),
            'notes'         => fake()->optional(0.4)->randomElement([
                'Reacción cutánea con urticaria.',
                'Antecedente de anafilaxia, evitar estrictamente.',
                'Provoca dificultad respiratoria leve.',
            ]),
            'diagnosed_at'  => fake()->optional(0.6)->dateTimeBetween('-10 years', '-1 month'),
        ];
    }

    public function severe(): static
    {
        return $this->state(['severity' => 'severe']);
    }
}
