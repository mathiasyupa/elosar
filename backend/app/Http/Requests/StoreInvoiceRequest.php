<?php

namespace App\Http\Requests;

use App\Data\InvoiceData;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation boundary for creating an invoice over HTTP (future API).
 *
 * Centralizes the input contract so validation is not duplicated between
 * Filament and the API, and produces a typed InvoiceData for InvoiceService.
 * Workspace authorization is enforced separately (tenant middleware/policy).
 */
class StoreInvoiceRequest extends FormRequest
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
            // Injected server-side by the controller (active workspace) — accepted
            // but ignored from the client; never trusted.
            'workspace_id'                => ['nullable', 'integer'],
            'patient_id'                  => ['required', 'integer'],
            'appointment_id'              => ['nullable', 'integer'],
            'attending_doctor_id'         => ['nullable', 'integer'],
            // Per-attention doctor revenue-share (%), set by the cashier at billing time.
            // The attending doctor's share for THIS attention: either a percentage
            // or a fixed amount — never both.
            'attending_doctor_commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100', 'prohibits:attending_doctor_commission_amount'],
            'attending_doctor_commission_amount' => ['nullable', 'numeric', 'min:0'],
            'issued_at'                   => ['required', 'date'],
            'notes'                       => ['nullable', 'string'],
            'discount_amount'             => ['nullable', 'numeric', 'min:0'],
            'buyer_id_type'               => ['nullable', 'string', 'size:2'],
            'buyer_id'                    => ['nullable', 'string', 'max:20'],
            'buyer_name'                  => ['nullable', 'string', 'max:300'],
            'buyer_address'               => ['nullable', 'string'],
            'buyer_email'                 => ['nullable', 'email'],
            'items'                       => ['required', 'array', 'min:1'],
            'items.*.description'         => ['required', 'string', 'max:255'],
            'items.*.quantity'            => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price'          => ['required', 'numeric', 'min:0'],
            'items.*.iva_rate'            => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.medical_service_id'  => ['nullable', 'integer'],
        ];
    }

    public function toData(): InvoiceData
    {
        return InvoiceData::fromArray($this->validated());
    }
}
