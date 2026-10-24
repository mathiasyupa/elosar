<?php

namespace App\Http\Requests;

use App\Models\MedicalCertificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalCertificateRequest extends FormRequest
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
        $patientId = (int) $this->route('id');

        $isParameterised = $this->input('type') === MedicalCertificate::TYPE_MEDICAL;

        return [
            'type' => ['required', 'string', Rule::in([
                MedicalCertificate::TYPE_MEDICAL,
                MedicalCertificate::TYPE_ATTENDANCE,
                MedicalCertificate::TYPE_REST,
                MedicalCertificate::TYPE_FITNESS,
            ])],
            'title'   => ['required', 'string', 'max:255'],
            // The parameterised certificate carries its content in its fields;
            // the free-text ones cannot exist without a body.
            'body'    => [$isParameterised ? 'nullable' : 'required', 'string'],
            'rest_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'valid_from'  => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'issued_at'   => ['nullable', 'date'],

            // Parameterised (labour) certificate.
            'city'             => ['nullable', 'string', 'max:120'],
            'hc_number'        => ['nullable', 'string', 'max:40'],
            'address'          => ['nullable', 'string', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:40'],
            'contingency_type' => ['nullable', 'string', 'max:60'],
            'company'          => ['nullable', 'string', 'max:160'],
            'activity'         => ['nullable', 'string', 'max:160'],
            'attended_at'      => ['nullable', 'date'],
            'symptoms'         => ['nullable', 'string', 'max:2000'],
            'diagnosis'        => ['nullable', 'string', 'max:2000'],
            'cie10_codes'      => ['nullable', 'string', 'max:120'],
            'show_cie10'       => ['sometimes', 'boolean'],
            'treatment'        => ['nullable', 'string', 'max:255'],
            'absolute_rest'    => ['nullable', 'boolean'],
            'medical_record_id' => [
                'nullable',
                'integer',
                Rule::exists('medical_records', 'id')->where(fn ($q) => $q->where('patient_id', $patientId)),
            ],
        ];
    }
}
