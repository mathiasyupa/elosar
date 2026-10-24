<?php

namespace App\Http\Requests\Elo;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A rule the doctor saves, one (`text`) or several proposals at once
 * (`texts`). Ten digits in a row look like a cédula: rules are about how
 * the doctor works, never about a patient.
 */
class EloRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = ['string', 'min:3', 'max:300', 'not_regex:/\d{10}/'];
        $patch = $this->isMethod('patch');

        return [
            'text' => $patch ? ['sometimes', ...$text] : ['required_without:texts', ...$text],
            'texts' => $patch ? ['prohibited'] : ['required_without:text', 'array', 'min:1', 'max:10'],
            'texts.*' => $text,
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'text.not_regex' => 'Una regla no lleva cédulas ni datos de un paciente.',
            'texts.*.not_regex' => 'Una regla no lleva cédulas ni datos de un paciente.',
        ];
    }

    /** @return list<string> */
    public function texts(): array
    {
        $texts = $this->has('texts') ? (array) $this->validated('texts') : [(string) $this->validated('text')];

        return array_values(array_filter(array_map('trim', $texts)));
    }
}
