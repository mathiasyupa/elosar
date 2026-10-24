<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Monetary fields are emitted as strings (decimal:2 / bcmath) — never floats.
 *
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /** Spanish display labels for the payment method (doctor UI). */
    private const METHOD_LABELS = [
        'cash'     => 'Efectivo',
        'transfer' => 'Transferencia',
        'card'     => 'Tarjeta',
        'other'    => 'Otro',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'invoice_id'          => $this->invoice_id,
            'amount'              => (string) $this->amount,
            'payment_method'      => $this->payment_method,
            'payment_method_label' => self::METHOD_LABELS[$this->payment_method] ?? $this->payment_method,
            'payment_date'        => $this->payment_date?->toDateString(),
            'reference'           => $this->reference,
            'notes'               => $this->notes,
            'registered_by'       => $this->whenLoaded('registeredByUser', fn () => $this->registeredByUser
                ? ['id' => $this->registeredByUser->id, 'name' => $this->registeredByUser->name]
                : null),
        ];
    }
}
