<?php

namespace Database\Factories;

use App\Models\Vaccine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vaccine>
 */
class VaccineFactory extends Factory
{
    protected $model = Vaccine::class;

    public function definition(): array
    {
        return [
            'code'         => null,
            'name'         => fake('es_ES')->unique()->words(2, true),
            'category'     => 'PAI',
            'max_doses'    => 3,
            'max_boosters' => 1,
            'is_active'    => true,
            'sort_order'   => 0,
        ];
    }
}
