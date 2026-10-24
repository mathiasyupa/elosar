<?php

namespace App\Http\Requests\Elo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The clinical picture Elo needs for a receta — and nothing that identifies
 * the patient. There is deliberately no patient_id: the atención may not be
 * saved yet, and no identifier ever travels to the AI provider.
 */
class EloPrescriptionRequest extends FormRequest
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
            'age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'sex' => ['nullable', 'in:male,female,other'],
            'weight_kg' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'illness' => ['nullable', 'string', 'max:3000'],
            'diagnoses' => ['required', 'array', 'min:1', 'max:10'],
            'diagnoses.*.code' => ['nullable', 'string', 'max:12'],
            'diagnoses.*.description' => ['nullable', 'string', 'max:255'],
            'allergies' => ['nullable', 'array', 'max:20'],
            'allergies.*' => ['string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['diagnoses.required' => 'Pon un diagnóstico para que Elo proponga una receta.'];
    }
}
