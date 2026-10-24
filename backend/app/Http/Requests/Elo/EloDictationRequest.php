<?php

namespace App\Http\Requests\Elo;

/**
 * What Elo needs to sort a dictated visit: the transcript plus the same
 * de-identified clinical picture as the other agents. Diagnoses are optional
 * — proposing them is part of the point.
 */
class EloDictationRequest extends EloPrescriptionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'transcript' => ['required', 'string', 'min:10', 'max:12000'],
            'exam' => ['nullable', 'string', 'max:3000'],
            'vitals_summary' => ['nullable', 'string', 'max:300'],
            'diagnoses' => ['nullable', 'array', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'transcript.required' => 'Elo no escuchó nada. Intenta de nuevo.',
            'transcript.min' => 'Elo escuchó muy poco. Habla un poco más y vuelve a intentar.',
        ];
    }
}
