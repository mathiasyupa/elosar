<?php

namespace App\Http\Requests;

use App\Rules\BoundedStructure;
use App\Models\Patient;
use App\Support\DocumentValidator;
use App\Support\BirthDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation boundary for creating a patient. workspace_id is injected
 * server-side from the active workspace (BelongsToWorkspace), never trusted from
 * the client. id_number is intentionally a loose string: minors and foreign
 * patients may not have an Ecuadorian cédula.
 */
class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Normalize the document tuple before validation and persistence. */
    protected function prepareForValidation(): void
    {
        $idNumber = trim((string) $this->input('id_number', ''));

        if ($idNumber === '') {
            return;
        }

        $suggestion = DocumentValidator::suggest($idNumber);
        $documentType = $this->input('document_type') ?: $suggestion['type'];
        $documentCountry = $this->input('document_country') ?: ($suggestion['country'] ?? 'OT');

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
            'name'                    => ['required', 'string', 'max:255'],
            'id_number'               => ['required', 'string', 'max:30'],
            'document_type'           => ['required', 'in:cedula,passport,foreign_id,other'],
            'document_country'        => ['required', 'string', 'size:2'],
            'birth_date'              => ['required', 'date', ...BirthDate::rules()],
            'gender'                  => ['required', 'in:male,female,other'],
            'civil_status'            => ['nullable', 'in:single,married,common_law,divorced,widowed,separated'],
            // Address took over from city on the intake form. City is kept
            // accepted-but-optional so older records and the importer, which
            // still carry it, do not start failing.
            'address'                 => ['required', 'string', 'max:255'],
            'city'                    => ['nullable', 'string', 'max:120'],
            'email'                   => ['nullable', 'email', 'max:255'],
            'phone'                   => ['required', 'string', 'max:40'],
            'blood_type'              => ['nullable', 'string', 'max:5'],
            'emergency_contact_name'  => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],
            'insurance_provider'      => ['nullable', 'string', 'max:255'],
            'insurance_policy_number' => ['nullable', 'string', 'max:120'],
            'personal_background'     => ['nullable', 'array', new BoundedStructure()],
            // LOPDP: informed consent flag + optional marketing opt-in. The
            // consent timestamp/version/author are stamped server-side.
            'data_consent'            => ['sometimes', 'boolean'],
            'consent_marketing'       => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Add document-type-aware validation after the base rules pass.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $data = $validator->getData();
            $error = DocumentValidator::validate(
                $data['document_type'] ?? null,
                $data['document_country'] ?? null,
                $data['id_number'] ?? ''
            );

            if ($error) {
                $validator->errors()->add('id_number', $error);
                return;
            }

            $message = self::duplicateDocumentMessage(
                [
                    'id_number' => $data['id_number'] ?? '',
                    'document_type' => $data['document_type'] ?? '',
                    'document_country' => $data['document_country'] ?? '',
                ],
            );
            if ($message !== null) {
                $validator->errors()->add('id_number', $message);
            }
        });
    }

    /**
     * Return a tenant-scoped conflict message without crossing workspaces.
     *
     * @param  array<string, mixed>  $data
     */
    public static function duplicateDocumentMessage(array $data, ?int $excludingPatientId = null): ?string
    {
        $existing = Patient::query()
            ->withTrashed()
            ->where('id_number', $data['id_number'])
            ->where('document_type', $data['document_type'])
            ->where('document_country', $data['document_country'])
            ->when($excludingPatientId !== null, fn ($query) => $query->whereKeyNot($excludingPatientId))
            ->first();

        if ($existing === null) {
            return null;
        }

        return $existing->trashed()
            ? 'Ese documento pertenece a un paciente archivado. Restáuralo antes de registrar uno nuevo.'
            : 'Ya existe un paciente con ese documento.';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return BirthDate::messages();
    }
}
