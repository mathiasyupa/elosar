<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The owner-only confirmation for emptying a newly created patient directory.
 *
 * The challenge is generated from the scoped patient set by
 * PatientDeletionService. It is a precondition, never a client-supplied list
 * of records or a workspace identifier.
 */
class PurgeAllPatientsRequest extends FormRequest
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
            'confirmation' => ['required', 'string', Rule::in(['ELIMINAR TODOS'])],
            'challenge' => ['required', 'string', 'max:200'],
        ];
    }
}
