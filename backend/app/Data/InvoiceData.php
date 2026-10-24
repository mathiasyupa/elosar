<?php

namespace App\Data;

/**
 * Typed input contract for InvoiceService::create().
 *
 * Replaces the raw `array $data` contract with a validated, explicit shape that
 * both Filament and the (future) API can build — the API via StoreInvoiceRequest.
 * Monetary fields are strings for bcmath precision.
 */
final class InvoiceData
{
    /**
     * @param  list<InvoiceItemData>  $items
     */
    public function __construct(
        public readonly int $workspaceId,
        public readonly int $patientId,
        public readonly string $issuedAt,
        public readonly array $items,
        public readonly ?int $appointmentId = null,
        public readonly ?string $notes = null,
        public readonly ?int $createdBy = null,
        public readonly ?int $attendingDoctorId = null,
        public readonly ?string $attendingDoctorCommissionPct = null,
        /** Fixed money the attending doctor gets for this attention (alternative to the %). */
        public readonly ?string $attendingDoctorCommissionAmount = null,
        public readonly ?int $issuedByUserId = null,
        public readonly string $discountAmount = '0',
        public readonly string $buyerIdType = '07',
        public readonly ?string $buyerId = null,
        public readonly ?string $buyerName = null,
        public readonly ?string $buyerAddress = null,
        public readonly ?string $buyerEmail = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            workspaceId: (int) ($data['workspace_id'] ?? 0),
            patientId: (int) ($data['patient_id'] ?? 0),
            issuedAt: (string) ($data['issued_at'] ?? ''),
            items: array_values(array_map(
                fn ($item) => $item instanceof InvoiceItemData ? $item : InvoiceItemData::fromArray($item),
                $data['items'] ?? [],
            )),
            appointmentId: isset($data['appointment_id']) ? (int) $data['appointment_id'] : null,
            notes: $data['notes'] ?? null,
            createdBy: isset($data['created_by']) ? (int) $data['created_by'] : null,
            attendingDoctorId: isset($data['attending_doctor_id']) ? (int) $data['attending_doctor_id'] : null,
            attendingDoctorCommissionPct: isset($data['attending_doctor_commission_pct']) && $data['attending_doctor_commission_pct'] !== ''
                ? (string) $data['attending_doctor_commission_pct']
                : null,
            attendingDoctorCommissionAmount: isset($data['attending_doctor_commission_amount']) && $data['attending_doctor_commission_amount'] !== ''
                ? (string) $data['attending_doctor_commission_amount']
                : null,
            issuedByUserId: isset($data['issued_by_user_id']) ? (int) $data['issued_by_user_id'] : null,
            discountAmount: (string) ($data['discount_amount'] ?? '0'),
            buyerIdType: (string) ($data['buyer_id_type'] ?? '07'),
            buyerId: $data['buyer_id'] ?? null,
            buyerName: $data['buyer_name'] ?? null,
            buyerAddress: $data['buyer_address'] ?? null,
            buyerEmail: $data['buyer_email'] ?? null,
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
            'workspace_id'    => $this->workspaceId,
            'patient_id'      => $this->patientId,
            'issued_at'       => $this->issuedAt,
            'items'           => array_map(fn (InvoiceItemData $item) => $item->toArray(), $this->items),
            'appointment_id'  => $this->appointmentId,
            'notes'           => $this->notes,
            'created_by'          => $this->createdBy,
            'attending_doctor_id' => $this->attendingDoctorId,
            'attending_doctor_commission_pct' => $this->attendingDoctorCommissionPct,
            'attending_doctor_commission_amount' => $this->attendingDoctorCommissionAmount,
            'issued_by_user_id'   => $this->issuedByUserId,
            'discount_amount' => $this->discountAmount,
            'buyer_id_type'   => $this->buyerIdType,
            'buyer_id'        => $this->buyerId,
            'buyer_name'      => $this->buyerName,
            'buyer_address'   => $this->buyerAddress,
            'buyer_email'     => $this->buyerEmail,
        ];
    }
}
