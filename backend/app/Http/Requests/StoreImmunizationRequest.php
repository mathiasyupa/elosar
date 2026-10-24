<?php

namespace App\Http\Requests;

use App\Models\PatientImmunization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation boundary for recording an administered vaccine dose for a patient.
 * workspace_id / patient_id / administered_by_user_id are injected server-side.
 */
class StoreImmunizationRequest extends FormRequest
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
            'vaccine_id'      => ['nullable', 'integer'],
            'vaccine_name'    => ['required', 'string', 'max:255'],
            'dose_label'      => ['required', Rule::in(array_keys(PatientImmunization::DOSE_LABELS))],
            'administered_at' => ['required', 'date'],
            'lot'             => ['nullable', 'string', 'max:80'],
            'notes'           => ['nullable', 'string', 'max:2000'],
            'reminder_at'     => ['nullable', 'date'],
        ];
    }
}
