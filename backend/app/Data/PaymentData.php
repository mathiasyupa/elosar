<?php

namespace App\Data;

/**
 * Typed input contract for PaymentService::register(). Amount is a string for
 * bcmath precision.
 */
final class PaymentData
{
    public function __construct(
        public readonly string $amount,
        public readonly string $paymentMethod,
        public readonly string $paymentDate,
        public readonly ?string $reference = null,
        public readonly ?string $notes = null,
        public readonly ?int $registeredBy = null,
        public readonly ?int $registeredByUserId = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            amount: (string) ($data['amount'] ?? '0'),
            paymentMethod: (string) ($data['payment_method'] ?? ''),
            paymentDate: (string) ($data['payment_date'] ?? ''),
            reference: $data['reference'] ?? null,
            notes: $data['notes'] ?? null,
            registeredBy: isset($data['registered_by']) ? (int) $data['registered_by'] : null,
            registeredByUserId: isset($data['registered_by_user_id']) ? (int) $data['registered_by_user_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount'                => $this->amount,
            'payment_method'        => $this->paymentMethod,
            'payment_date'          => $this->paymentDate,
            'reference'             => $this->reference,
            'notes'                 => $this->notes,
            'registered_by'         => $this->registeredBy,
            'registered_by_user_id' => $this->registeredByUserId,
        ];
    }
}
