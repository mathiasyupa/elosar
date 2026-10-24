<?php

namespace App\Data;

/**
 * Typed input for a single invoice line. Monetary fields are kept as strings
 * for bcmath precision (never floats). See InvoiceData.
 */
final class InvoiceItemData
{
    public function __construct(
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly string $ivaRate = '0.00',
        public readonly string $discountPercentage = '0',
        public readonly ?int $medicalServiceId = null,
        public readonly ?int $sortOrder = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            description: (string) ($data['description'] ?? ''),
            quantity: (string) ($data['quantity'] ?? '0'),
            unitPrice: (string) ($data['unit_price'] ?? '0'),
            ivaRate: (string) ($data['iva_rate'] ?? '0.00'),
            discountPercentage: (string) ($data['discount_percentage'] ?? '0'),
            medicalServiceId: isset($data['medical_service_id']) ? (int) $data['medical_service_id'] : null,
            sortOrder: isset($data['sort_order']) ? (int) $data['sort_order'] : null,
        );
    }

    /**
     * Snake_case shape consumed by InvoiceService.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description'         => $this->description,
            'quantity'            => $this->quantity,
            'unit_price'          => $this->unitPrice,
            'iva_rate'            => $this->ivaRate,
            'discount_percentage' => $this->discountPercentage,
            'medical_service_id'  => $this->medicalServiceId,
            'sort_order'          => $this->sortOrder,
        ];
    }
}
