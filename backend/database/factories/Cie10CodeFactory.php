<?php

namespace Database\Factories;

use App\Models\Cie10Code;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cie10Code>
 *
 * Global ICD-10 (CIE-10) catalog entries. Not workspace-scoped. Descriptions are
 * Spanish (official PAHO/WHO edition) per the clinical-content convention.
 */
class Cie10CodeFactory extends Factory
{
    protected $model = Cie10Code::class;

    public function definition(): array
    {
        return [
            'code'        => strtoupper(fake()->unique()->bothify('?##.#')),
            'description' => fake('es_ES')->sentence(4),
            'category'    => fake()->optional()->word(),
        ];
    }
}
