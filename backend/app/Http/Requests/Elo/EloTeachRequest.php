<?php

namespace App\Http\Requests\Elo;

use Illuminate\Foundation\Http\FormRequest;

class EloTeachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:10'],
            'history.*.role' => ['required', 'in:doctor,elo'],
            'history.*.text' => ['required', 'string', 'max:1000'],
        ];
    }
}
