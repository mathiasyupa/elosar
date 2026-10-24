<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation boundary for adding an allergy to a patient. Isolation is enforced
 * by resolving the parent patient through the workspace-scoped query in the
 * controller; the allergy is then created via the patient relationship.
 */
class StoreAllergyRequest extends FormRequest
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
            'allergen_name' => ['required', 'string', 'max:255'],
            'allergen_type' => ['nullable', 'in:drug,food,environmental,other'],
            'severity'      => ['nullable', 'in:mild,moderate,severe'],
            'notes'         => ['nullable', 'string'],
            'diagnosed_at'  => ['nullable', 'date'],
        ];
    }
}
