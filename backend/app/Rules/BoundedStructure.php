<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Guards free-form JSON/array fields (e.g. Patient::personal_background) against
 * abuse: caps the serialized byte size and the nesting depth so a client cannot
 * persist a multi-megabyte or deeply-nested blob. Intentionally does NOT enforce
 * a key allowlist — the structure is open by design (nested habits + a free
 * "other" key-value map).
 */
class BoundedStructure implements ValidationRule
{
    public function __construct(
        private readonly int $maxBytes = 20000,
        private readonly int $maxDepth = 5,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // 'array' rule handles the type; nothing to bound otherwise.
        }

        $encoded = json_encode($value);

        if ($encoded === false || strlen($encoded) > $this->maxBytes) {
            $fail('El campo :attribute es demasiado grande.');

            return;
        }

        if ($this->depth($value) > $this->maxDepth) {
            $fail('El campo :attribute tiene una estructura demasiado anidada.');
        }
    }

    private function depth(array $array): int
    {
        $max = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $max = max($max, 1 + $this->depth($value));
            }
        }

        return $max;
    }
}
