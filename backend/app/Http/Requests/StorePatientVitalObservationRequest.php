<?php

namespace App\Http\Requests;

use App\Services\Obstetrics\ScoreMama;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePatientVitalObservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'weight_kg' => ['nullable', 'numeric', 'between:0,500'],
            'height_cm' => ['nullable', 'numeric', 'between:0,300'],
            'head_circumference_cm' => ['nullable', 'numeric', 'between:20,70'],
            'length_height_measurement_position' => ['nullable', Rule::in(['recumbent_length', 'standing_height'])],
            'blood_pressure_sys' => ['nullable', 'integer', 'between:50,300'],
            'blood_pressure_dia' => ['nullable', 'integer', 'between:30,200'],
            'heart_rate' => ['nullable', 'integer', 'between:20,300'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'oxygen_saturation' => ['nullable', 'numeric', 'between:50,100'],
            'respiratory_rate' => ['nullable', 'integer', 'between:5,80'],
            'oxygen_therapy' => ['nullable', Rule::in(ScoreMama::OXYGEN_THERAPY)],
            'consciousness_level' => ['nullable', Rule::in(ScoreMama::CONSCIOUSNESS)],
            'urine_output_ml_h' => ['nullable', 'numeric', 'between:0,99999.9'],
            'urine_output_not_quantifiable' => ['nullable', 'boolean'],
            'bmi' => ['prohibited'],
            'score_mama_total' => ['prohibited'],
            'score_mama_level' => ['prohibited'],
            'score_mama_protocol' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $fields = array_keys($this->rules());
            foreach ($fields as $field) {
                if (in_array($field, ['bmi', 'score_mama_total', 'score_mama_level', 'score_mama_protocol'], true)) {
                    continue;
                }

                $value = $this->input($field);
                if ($value !== null && $value !== '' && $value !== false) {
                    return;
                }
            }

            $validator->errors()->add('vitals', 'Registra al menos un signo vital.');
        }];
    }
}
