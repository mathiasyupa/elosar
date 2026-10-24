<?php

namespace App\Http\Requests\Elo;

use App\Models\EloSuggestionEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EloEventRequest extends FormRequest
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
            'agent' => ['required', Rule::in(EloSuggestionEvent::AGENTS)],
            'outcome' => ['required', Rule::in(EloSuggestionEvent::OUTCOMES)],
            'items_count' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
    }
}
