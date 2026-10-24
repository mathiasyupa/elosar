<?php

namespace Database\Factories;

use App\Models\MedicalService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalService>
 */
class MedicalServiceFactory extends Factory
{
    protected $model = MedicalService::class;

    private static array $services = [
        'Consulta médica general',
        'Consulta de especialidad',
        'Electrocardiograma',
        'Ecografía abdominal',
        'Radiografía de tórax',
        'Examen de laboratorio básico',
        'Curación de heridas',
        'Inyección intramuscular',
        'Control de presión arterial',
        'Nebulización',
        'Extracción dental',
        'Limpieza dental',
        'Espirometría',
        'Control prenatal',
        'Vacunación',
    ];

    public function definition(): array
    {
        return [
            'name'        => fake()->unique()->randomElement(self::$services),
            'description' => fake()->optional(0.6)->sentence(),
            'base_price'  => fake()->randomFloat(2, 20, 200),
            'iva_rate'    => fake()->randomElement(['0.00', '15.00']),
            'is_active'   => true,
            'sort_order'  => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withIva(): static
    {
        return $this->state(['iva_rate' => '15.00']);
    }

    public function withoutIva(): static
    {
        return $this->state(['iva_rate' => '0.00']);
    }
}
