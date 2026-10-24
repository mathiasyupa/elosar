<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation boundary for creating an appointment over the API.
 *
 * `patient_id` and `doctor_id` are validated for existence within the active
 * workspace by the controller (which scopes the lookups); here we only enforce
 * shape. workspace_id is injected server-side from the active workspace.
 */
class StoreAppointmentRequest extends FormRequest
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
            'patient_id'       => ['required', 'integer'],
            'doctor_id'        => ['nullable', 'integer'],
            'appointment_date' => ['required', 'date'],
            // Closed set: free text let the web form store the Spanish label
            // while the backend stored the English key, so neither matched.
            'type'             => ['nullable', Rule::in(Appointment::TYPES)],
            'medical_service_id' => ['nullable', 'integer'],
            'channel'          => ['nullable', 'string', 'max:50'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'status'           => ['nullable', 'in:scheduled,completed,cancelled'],
            'notes'            => ['nullable', 'string'],
        ];
    }
}
