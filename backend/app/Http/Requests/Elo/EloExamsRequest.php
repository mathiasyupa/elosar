<?php

namespace App\Http\Requests\Elo;

/** Exams follow the diagnosis, like the receta; the exam text helps too. */
class EloExamsRequest extends EloPrescriptionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'exam' => ['nullable', 'string', 'max:3000'],
            'vitals_summary' => ['nullable', 'string', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['diagnoses.required' => 'Pon un diagnóstico para que Elo proponga exámenes.'];
    }
}
