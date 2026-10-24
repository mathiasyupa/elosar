<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportAppointmentRequest extends FormRequest
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
            'rows'                       => ['required', 'array'],
            'rows.*.appointment_date'    => ['required', 'date'],
            'rows.*.id_number'           => ['required', 'string', 'max:30'],
            'rows.*.document_type'       => ['nullable', 'in:cedula,passport,foreign_id,other'],
            'rows.*.document_country'    => ['nullable', 'string', 'size:2'],
            'rows.*.status'              => ['nullable', 'string'],
            'rows.*.notes'               => ['nullable', 'string'],
            'rows.*.service_name'        => ['nullable', 'string'],
            'rows.*.channel'             => ['nullable', 'string'],
        ];
    }
}
