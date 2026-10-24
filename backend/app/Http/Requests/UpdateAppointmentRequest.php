<?php

namespace App\Http\Requests;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of an existing appointment (reschedule / edit). Every field is
 * optional so the client may patch a single attribute.
 */
class UpdateAppointmentRequest extends FormRequest
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
            'patient_id'       => ['sometimes', 'integer'],
            'doctor_id'        => ['sometimes', 'integer'],
            'appointment_date' => ['sometimes', 'date'],
            'type'             => ['sometimes', 'nullable', Rule::in(Appointment::TYPES)],
            'medical_service_id' => ['sometimes', 'nullable', 'integer'],
            'channel'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:480'],
            'status'           => ['sometimes', 'in:scheduled,completed,cancelled'],
            'notes'            => ['sometimes', 'nullable', 'string'],
        ];
    }
}
