<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation boundary for the AI assistant chat endpoint. Authorization is
 * enforced by the `permission:assistant.use` route middleware, not here.
 */
class AiChatRequest extends FormRequest
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
            'message'        => ['required', 'string', 'max:2000'],
            'history'        => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:4000'],
        ];
    }
}
