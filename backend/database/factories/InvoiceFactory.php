<?php

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal  = fake()->randomFloat(2, 20, 500);
        $ivaAmount = round($subtotal * 0.15, 2);
        $total     = round($subtotal + $ivaAmount, 2);

        return [
            'invoice_number'      => 'F-' . str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'issued_at'           => fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d'),
            'status'              => 'issued',
            'subtotal'            => $subtotal,
            'iva_amount'          => $ivaAmount,
            'total'               => $total,
            'amount_paid'         => '0.00',
            'notes'               => fake()->optional(0.3)->sentence(),
            'cancelled_at'        => null,
            'cancellation_reason' => null,
            'voided_at'           => null,
        ];
    }

    /**
     * Factura completamente pagada.
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'status'      => 'paid',
                'amount_paid' => $attributes['total'],
            ];
        });
    }

    /**
     * Partially paid invoice.
     */
    public function partial(): static
    {
        return $this->state(function (array $attributes) {
            $partial = round($attributes['total'] / 2, 2);

            return [
                'status'      => 'partial',
                'amount_paid' => $partial,
            ];
        });
    }

    /**
     * Factura cancelada.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status'              => 'cancelled',
            'cancelled_at'        => now(),
            'cancellation_reason' => fake()->sentence(),
            'amount_paid'         => '0.00',
        ]);
    }
}
