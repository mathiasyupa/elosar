<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Monetary fields are emitted as strings — they come from `decimal:2` casts and
 * bcmath accessors. Never cast them to float on the client; parse as decimal.
 *
 * @mixin \App\Models\Invoice
 */
class InvoiceResource extends JsonResource
{
    /** Spanish display labels for the invoice status badge (doctor UI). */
    private const STATUS_LABELS = [
        'draft'     => 'Borrador',
        'issued'    => 'Emitida',
        'partial'   => 'Pago parcial',
        'paid'      => 'Pagada',
        'cancelled' => 'Anulada',
        'void'      => 'Anulada',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'invoice_number' => $this->invoice_number,
            'issued_at'      => $this->issued_at?->toDateString(),
            'status'         => $this->status,
            'status_label'   => self::STATUS_LABELS[$this->status] ?? $this->status,

            // Monetary values as strings (bcmath / decimal:2 — never floats).
            'subtotal'        => (string) $this->subtotal,
            'iva_amount'      => (string) $this->iva_amount,
            'discount_amount' => (string) $this->discount_amount,
            'total'           => (string) $this->total,
            'amount_paid'     => (string) $this->amount_paid,
            'amount_due'      => $this->amount_due,

            'notes' => $this->notes,

            // Buyer fiscal data.
            'buyer_id_type' => $this->buyer_id_type,
            'buyer_id'      => $this->buyer_id,
            'buyer_name'    => $this->buyer_name,

            // SRI e-invoicing status (raw; the front-end maps it for display).
            'sri_status'               => $this->sri_status,
            'sri_authorization_number' => $this->sri_authorization_number,
            // The fiscal number (estab-ptoEmi-secuencial). Shown on the first
            // step of the timeline — it is what the SRI and the RIDE call the
            // voucher, as opposed to the internal "F-0357".
            'sri_sequence_number'      => $this->sri_sequence_number,
            'sri_authorization_date'   => $this->sri_authorization_date?->toIso8601String(),
            'sri_last_attempt_at'      => $this->sri_last_attempt_at?->toIso8601String(),
            // Curated failure info. Built by SriError, which guarantees no
            // exception text ever reaches this response — `sri_last_error` is
            // the raw one and stays hidden.
            'sri_error'                => $this->sri_error,
            // Seconds after which a `pending` voucher is stuck, not slow.
            // Derived from the job's retry budget so the client never repeats it.
            'sri_stale_after_seconds'  => \App\Jobs\AuthorizeSriVoucherJob::staleAfterSeconds(),

            'patient' => PatientResource::make($this->whenLoaded('patient')),

            // Attribution: who attended the patient and who issued the invoice.
            'attending_doctor' => $this->whenLoaded('attendingDoctor', fn () => $this->attendingDoctor
                ? ['id' => $this->attendingDoctor->id, 'name' => $this->attendingDoctor->name]
                : null),
            // Per-attention doctor revenue-share captured at billing time (may be null).
            // What the attending doctor actually earns from this invoice, computed
            // on money COLLECTED (same math as the settlement report). Only set
            // when the viewer is the employed doctor themselves.
            'doctor_share' => isset($this->doctor_share) ? (string) $this->doctor_share : null,
            'attending_doctor_commission_amount' => $this->attending_doctor_commission_amount !== null
                ? (string) $this->attending_doctor_commission_amount
                : null,
            'attending_doctor_commission_pct' => $this->attending_doctor_commission_pct !== null
                ? (string) $this->attending_doctor_commission_pct
                : null,
            'issued_by' => $this->whenLoaded('issuedBy', fn () => $this->issuedBy
                ? ['id' => $this->issuedBy->id, 'name' => $this->issuedBy->name]
                : null),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id'                  => $item->id,
                'description'         => $item->description,
                'quantity'            => (string) $item->quantity,
                'unit_price'          => (string) $item->unit_price,
                'discount_percentage' => (string) $item->discount_percentage,
                'discount_amount'     => (string) $item->discount_amount,
                'iva_rate'            => (string) $item->iva_rate,
                'iva_amount'          => (string) $item->iva_amount,
                'line_total'          => (string) $item->line_total,
            ])),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id'             => $payment->id,
                'amount'         => (string) $payment->amount,
                'payment_method' => $payment->payment_method,
                'payment_date'   => $payment->payment_date?->toDateString(),
                'reference'      => $payment->reference,
            ])),
        ];
    }
}
