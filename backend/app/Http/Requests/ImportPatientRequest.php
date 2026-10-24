<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk import of patients from an external file.
 *
 * Contact fields (address, email) are intentionally optional because legacy
 * exports often omit or mask them. The service normalizes and rejects rows
 * that still cannot be stored safely.
 */
class ImportPatientRequest extends FormRequest
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
            'dry_run' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.source_line' => ['nullable', 'integer', 'min:2'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.id_number' => ['required', 'string', 'max:30'],
            'rows.*.birth_date' => ['required', 'date_format:Y-m-d'],
            'rows.*.gender' => ['nullable', 'string'],
            'rows.*.address' => ['nullable', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'email', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:40'],
            'rows.*.document_type' => ['nullable', 'in:cedula,passport,foreign_id,other'],
            'rows.*.document_country' => ['nullable', 'string', 'size:2'],
        ];
    }
}
