<?php

namespace App\Http\Requests;

use App\Rules\BoundedStructure;
use App\Models\Patient;
use App\Support\DocumentValidator;
use App\Support\BirthDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Partial update of an existing patient. Every field is optional.
 */
class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Keep a complete, non-null document tuple when any document field changes. */
    protected function prepareForValidation(): void
    {
        if (! $this->exists('id_number') && ! $this->exists('document_type') && ! $this->exists('document_country')) {
            return;
        }

        $patient = Patient::query()->find($this->route('id'));
        $idNumber = trim((string) ($this->input('id_number') ?? $patient?->id_number ?? ''));

        if ($idNumber === '') {
            return;
        }

        $suggestion = DocumentValidator::suggest($idNumber);
        $documentType = $this->input('document_type') ?: $patient?->document_type ?: $suggestion['type'];
        $documentCountry = $this->input('document_country') ?: $patient?->document_country ?: ($suggestion['country'] ?? 'OT');

        $this->merge([
            'id_number' => $idNumber,
            'document_type' => $documentType,
            'document_country' => mb_strtoupper((string) $documentCountry),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'                    => ['sometimes', 'string', 'max:255'],
            'id_number'               => ['sometimes', 'string', 'max:30'],
            'document_type'           => ['sometimes', 'required', 'in:cedula,passport,foreign_id,other'],
            'document_country'        => ['sometimes', 'required', 'string', 'size:2'],
            'birth_date'              => ['sometimes', 'nullable', 'date', ...BirthDate::rules()],
            'gender'                  => ['sometimes', 'in:male,female,other'],
            'civil_status'            => ['sometimes', 'nullable', 'in:single,married,common_law,divorced,widowed,separated'],
            'city'                    => ['sometimes', 'nullable', 'string', 'max:120'],
            'address'                 => ['sometimes', 'nullable', 'string', 'max:255'],
            'email'                   => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'                   => ['sometimes', 'nullable', 'string', 'max:40'],
            'blood_type'              => ['sometimes', 'nullable', 'string', 'max:5'],
            'emergency_contact_name'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'insurance_provider'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'insurance_policy_number' => ['sometimes', 'nullable', 'string', 'max:120'],
            'personal_background'     => ['sometimes', 'nullable', 'array', new BoundedStructure()],
            'data_consent'            => ['sometimes', 'boolean'],
            'consent_marketing'       => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();

            if (! array_key_exists('id_number', $data)) {
                return;
            }

            $error = DocumentValidator::validate(
                $data['document_type'] ?? null,
                $data['document_country'] ?? null,
                $data['id_number'] ?? ''
            );

            if ($error) {
                $validator->errors()->add('id_number', $error);
                return;
            }

            // Composite uniqueness check, scoped to the document tuple and
            // ignoring the patient being updated.
            $message = StorePatientRequest::duplicateDocumentMessage(
                [
                    'id_number' => $data['id_number'],
                    'document_type' => $data['document_type'] ?? 'cedula',
                    'document_country' => $data['document_country'] ?? 'EC',
                ],
                (int) $this->route('id'),
            );

            if ($message !== null) {
                $validator->errors()->add('id_number', $message);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return BirthDate::messages();
    }
}
