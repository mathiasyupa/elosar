<?php

namespace Database\Factories;

use App\Models\Study;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Study>
 */
class StudyFactory extends Factory
{
    protected $model = Study::class;

    public function definition(): array
    {
        return [
            'workspace_id' => null,
            'type'         => Study::TYPE_LABORATORY,
            'category'     => 'HEMATOLÓGICO',
            'name'         => fake('es_ES')->words(2, true),
            'code'         => null,
            'is_active'    => true,
            'sort_order'   => 0,
        ];
    }

    public function imaging(): static
    {
        return $this->state(['type' => Study::TYPE_IMAGING, 'category' => 'Ecografía']);
    }
}
