<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'amount'         => fake()->randomFloat(2, 10, 300),
            'payment_method' => fake()->randomElement(['cash', 'transfer', 'card']),
            'payment_date'   => fake()->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
            'reference'      => fake()->optional(0.5)->bothify('REF-####??'),
            'notes'          => fake()->optional(0.3)->sentence(),
        ];
    }

    public function cash(): static
    {
        return $this->state(['payment_method' => 'cash']);
    }

    public function transfer(): static
    {
        return $this->state([
            'payment_method' => 'transfer',
            'reference'      => fake()->bothify('TRF-########'),
        ]);
    }

    public function card(): static
    {
        return $this->state(['payment_method' => 'card']);
    }
}
