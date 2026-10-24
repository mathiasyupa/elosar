<?php

namespace Database\Factories;

use App\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Specialty>
 */
class SpecialtyFactory extends Factory
{
    protected $model = Specialty::class;

    private static array $specialties = [
        'Cardiología', 'Pediatría', 'Ginecología', 'Medicina Interna',
        'Cirugía General', 'Dermatología', 'Traumatología', 'Oftalmología',
        'Psiquiatría', 'Endocrinología', 'Neurología', 'Urología',
        'Oncología', 'Geriatría', 'Medicina Familiar',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(self::$specialties),
        ];
    }
}
