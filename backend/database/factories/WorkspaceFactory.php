<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Consultorio',
            'type' => 'personal',
            'invite_code' => null,
            'owner_doctor_id' => null,
            'logo_path' => null,
            'prescription_settings' => null,
        ];
    }

    public function clinic(): static
    {
        return $this->state([
            'type' => 'clinic',
            'invite_code' => strtoupper(Str::random(8)),
        ]);
    }

    public function withPrescriptionSettings(): static
    {
        return $this->state([
            'prescription_settings' => [
                'header' => fake()->company(),
                'subheader' => fake()->catchPhrase(),
                'address' => fake()->address(),
                'phone' => fake()->phoneNumber(),
                'email' => fake()->safeEmail(),
            ],
        ]);
    }
}
