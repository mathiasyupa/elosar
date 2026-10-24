<?php

namespace App\Http\Requests;

use App\Services\Obstetrics\PrenatalScreenings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One prenatal screening result.
 *
 * `code` is validated against the catalogue transcribed from the MSP guideline,
 * so a typo cannot create a screening that exists nowhere in the schedule and
 * then sits on the perinatal card looking official.
 */
class StorePregnancyScreeningRequest extends FormRequest
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
            'code'      => ['required', Rule::in(PrenatalScreenings::codes())],
            'result'    => ['nullable', 'string', 'max:120'],
            'result_at' => ['nullable', 'date', 'before_or_equal:today'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.in'                  => 'Ese examen no está en el calendario de control prenatal del MSP.',
            'result_at.before_or_equal' => 'La fecha del resultado no puede ser futura.',
        ];
    }
}
