<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of the authenticated doctor's professional profile
 * (Perfil Profesional screen). Account email/password live on the User and are
 * out of scope here.
 */
class UpdateDoctorProfileRequest extends FormRequest
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
        $doctorId = $this->user()?->doctor?->id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('doctors', 'email')->ignore($doctorId),
            ],
            'medical_license' => ['sometimes', 'nullable', 'string', 'max:120'],
            'acess_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'senescyt_registration' => ['sometimes', 'nullable', 'string', 'max:60'],
            // «Dr.» / «Dra.»: how every document signs.
            'honorific' => ['sometimes', 'nullable', 'in:Dr.,Dra.'],
            'uses_physical_stamp_signature' => ['sometimes', 'boolean'],
            // Specialties are editable after registration. The first id is the
            // primary specialty (printed on prescriptions) — order matters.
            'specialty_ids' => ['sometimes', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct', 'exists:specialties,id'],
        ];
    }
}
