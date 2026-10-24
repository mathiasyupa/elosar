<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Monetary fields are emitted as strings (decimal:2 / bcmath) — never floats.
 *
 * @mixin \App\Models\CreditNote
 */
class CreditNoteResource extends JsonResource
{
    private const STATUS_LABELS = [
        'draft'  => 'Borrador',
        'issued' => 'Emitida',
        'void'   => 'Anulada',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'credit_note_number' => $this->credit_note_number,
            'invoice_id'         => $this->invoice_id,
            'issued_at'          => $this->issued_at?->toDateString(),
            'reason'             => $this->reason,
            'amount'             => (string) $this->amount,
            'status'             => $this->status,
            'status_label'       => self::STATUS_LABELS[$this->status] ?? $this->status,
            'sri_status'         => $this->sri_status,
            // The fiscal number (estab-ptoEmi-secuencial), the SRI/RIDE name for
            // the voucher, as opposed to the internal "NC-0042".
            'sri_sequence_number'     => $this->sri_sequence_number,
            'sri_authorization_date'  => $this->sri_authorization_date?->toIso8601String(),
            'sri_last_attempt_at'     => $this->sri_last_attempt_at?->toIso8601String(),
            // Curated failure info (see InvoiceResource) — `sri_last_error`
            // (raw) stays hidden.
            'sri_error'               => $this->sri_error,
            // Seconds after which a `pending` voucher is stuck, not slow.
            'sri_stale_after_seconds' => \App\Jobs\AuthorizeSriVoucherJob::staleAfterSeconds(),

            'invoice' => $this->whenLoaded('invoice', fn () => [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
            ]),
        ];
    }
}
