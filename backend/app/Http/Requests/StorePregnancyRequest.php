<?php

namespace App\Http\Requests;

use App\Services\Obstetrics\GestationalAge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation boundary for opening a pregnancy.
 *
 * `workspace_id`, `patient_id` and `opened_by_user_id` are injected server-side.
 * `edd_date` / `edd_protocol` are NOT accepted at all — they are derived by
 * `GestationalAge`, and the controller strips them anyway.
 */
class StorePregnancyRequest extends FormRequest
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
            'lmp_date'         => ['nullable', 'date'],
            'lmp_is_reliable'  => ['nullable', 'boolean'],
            'ultrasound_date'  => ['nullable', 'date'],
            'ultrasound_weeks' => ['nullable', 'integer', 'min:0', 'max:42'],
            'ultrasound_days'  => ['nullable', 'integer', 'min:0', 'max:6'],
            'dating_source'    => ['nullable', Rule::in(GestationalAge::DATING_SOURCES)],
            'fetus_count'      => ['nullable', 'integer', 'min:1', 'max:6'],
            'risk_notes'       => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $validator->getData();

            // A pregnancy nobody can date is a row that will show "sin fecha"
            // forever and never reach the probable-deliveries list. Better to say
            // so now than to let the doctor discover it three visits later.
            $hasLmp = ! empty($data['lmp_date']);
            $hasScan = ! empty($data['ultrasound_date']) && isset($data['ultrasound_weeks']);

            if (! $hasLmp && ! $hasScan) {
                $validator->errors()->add(
                    'lmp_date',
                    'Indica la FUM o una ecografía con su edad gestacional: sin una de las dos no se puede calcular la fecha probable de parto.'
                );
            }

            // An ultrasound date without the weeks it measured dates nothing.
            if (! empty($data['ultrasound_date']) && ! isset($data['ultrasound_weeks'])) {
                $validator->errors()->add(
                    'ultrasound_weeks',
                    'Indica las semanas que midió la ecografía.'
                );
            }

            if (! empty($data['lmp_date']) && strtotime($data['lmp_date']) > time()) {
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'dating_source.in' => 'El origen de la datación debe ser FUM o ecografía.',
        ];
    }
}
