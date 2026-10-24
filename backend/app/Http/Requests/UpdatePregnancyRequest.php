<?php

namespace App\Http\Requests;

use App\Services\Obstetrics\GestationalAge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Correcting a pregnancy already on file — typically the dating, once an early
 * scan comes back and the clinician decides it dates better than the last period.
 *
 * `status` is not here: closing a pregnancy is its own endpoint, because it also
 * records an outcome and who closed it.
 */
class UpdatePregnancyRequest extends FormRequest
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
            'lmp_date'         => ['sometimes', 'nullable', 'date'],
            'lmp_is_reliable'  => ['sometimes', 'nullable', 'boolean'],
            'ultrasound_date'  => ['sometimes', 'nullable', 'date'],
            'ultrasound_weeks' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:42'],
            'ultrasound_days'  => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6'],
            'dating_source'    => ['sometimes', Rule::in(GestationalAge::DATING_SOURCES)],
            'fetus_count'      => ['sometimes', 'integer', 'min:1', 'max:6'],
            'risk_notes'       => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            if (array_key_exists('lmp_date', $data) && ! empty($data['lmp_date'])
                && strtotime($data['lmp_date']) > time()) {
                $validator->errors()->add('lmp_date', 'La FUM no puede ser una fecha futura.');
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'lmp_date'         => 'FUM',
            'ultrasound_date'  => 'fecha de la ecografía',
            'ultrasound_weeks' => 'semanas por ecografía',
            'dating_source'    => 'origen de la datación',
        ];
    }
}
