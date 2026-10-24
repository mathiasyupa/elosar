<?php

namespace App\Http\Requests\Elo;

/**
 * What Elo needs to tidy the note: the visit's own text. Diagnoses are
 * optional here — proposing them is the point.
 */
class EloNoteRequest extends EloPrescriptionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'reason' => ['nullable', 'required_without_all:illness,exam', 'string', 'max:1000'],
            'exam' => ['nullable', 'string', 'max:3000'],
            'vitals_summary' => ['nullable', 'string', 'max:300'],
            'diagnoses' => ['nullable', 'array', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['reason.required_without_all' => 'Escribe algo de la consulta para que Elo lo ordene.'];
    }
}
