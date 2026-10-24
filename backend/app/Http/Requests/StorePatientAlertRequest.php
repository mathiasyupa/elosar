<?php

namespace App\Http\Requests;

use App\Models\PatientAlert;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A standing warning about a patient. `patient_id` and the author are injected
 * server-side; `resolved_at` is not accepted at all — closing an alert has its
 * own endpoint, so the moment is the server's clock and not the client's.
 */
class StorePatientAlertRequest extends FormRequest
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
            // Short on purpose: this is pinned next to the patient's name on every
            // screen. A paragraph there stops being read, which defeats it.
            'text'     => ['required', 'string', 'max:255'],
            'severity' => ['sometimes', Rule::in(array_keys(PatientAlert::SEVERITIES))],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['text' => 'aviso', 'severity' => 'gravedad'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'text.required' => 'Escribe el aviso.',
            'text.max'      => 'El aviso debe caber en una línea: se muestra junto al nombre del paciente en todas las pantallas.',
        ];
    }
}
