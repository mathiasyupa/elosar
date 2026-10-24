<?php

namespace App\Http\Requests;

use App\Models\Pregnancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Closing a pregnancy. Its own endpoint rather than a status field on update,
 * because closing is the moment the outcome is recorded and it is what frees the
 * patient for a new pregnancy — the partial unique index depends on it.
 */
class ClosePregnancyRequest extends FormRequest
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
            'status'        => ['sometimes', Rule::in(['completed', 'lost', 'unknown'])],
            'outcome'       => ['required', Rule::in(array_keys(Pregnancy::OUTCOMES))],
            'outcome_date'  => ['required', 'date', 'before_or_equal:today'],
            'outcome_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'outcome'      => 'desenlace',
            'outcome_date' => 'fecha del desenlace',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'outcome.required'              => 'Indica cómo terminó el embarazo.',
            'outcome_date.before_or_equal'  => 'La fecha del desenlace no puede ser futura.',
        ];
    }
}
