<?php

namespace App\Http\Requests;

use App\Data\PaymentData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation boundary for registering a payment over HTTP (future API).
 * Produces a typed PaymentData for PaymentService.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount'         => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'in:cash,transfer,card,other'],
            'payment_date'   => ['required', 'date'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'notes'          => ['nullable', 'string'],
        ];
    }

    public function toData(): PaymentData
    {
        return PaymentData::fromArray($this->validated());
    }
}
