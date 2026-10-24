<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Walk-in: patient arrives without a prior booking. Creates (or picks) a patient,
 * schedules now, and checks in immediately (status checked_in).
 */
class StoreWalkInAppointmentRequest extends FormRequest
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
            'patient_id'  => ['nullable', 'integer', 'required_without:name'],
            'name'        => ['nullable', 'string', 'max:255', 'required_without:patient_id'],
            'id_number'   => ['nullable', 'string', 'max:20', 'required_without:patient_id'],
            'doctor_id'   => ['required', 'integer'],
            'notes'       => ['nullable', 'string', 'max:500'],
        ];
    }
}
