<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an Ecuadorian identification number locally (no external API).
 *
 * Supports:
 *   - cedula (10 digits): mod-10 check digit, province 01-24, third digit < 6
 *   - RUC (13 digits): natural person (mod-10 on first 10) or juridical/public
 *     entity (mod-11 on first 9), establishment suffix must end in "001"
 *
 * By default it auto-detects the type from the length. Pass a $type to force
 * one. Passports / foreign IDs ('06') and final consumer ('07') are skipped.
 */
class EcuadorianId implements ValidationRule
{
    /**
     * @param  string  $type  'auto' | 'cedula' | 'ruc'
     */
    public function __construct(private string $type = 'auto')
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = preg_replace('/\D/', '', (string) $value);

        if ($id === '') {
            return; // let `required` handle emptiness
        }

        $valid = match ($this->type) {
            'cedula' => $this->isValidCedula($id),
            'ruc'    => $this->isValidRuc($id),
            default  => strlen($id) === 13 ? $this->isValidRuc($id) : $this->isValidCedula($id),
        };

        if (! $valid) {
            $fail('El número de identificación ecuatoriano no es válido.');
        }
    }

    /**
     * Convenience factory for cédula-only validation.
     */
    public static function cedula(): self
    {
        return new self('cedula');
    }

    /**
     * Convenience factory for RUC-only validation.
     */
    public static function ruc(): self
    {
        return new self('ruc');
    }

    private function isValidCedula(string $id): bool
    {
        if (! preg_match('/^\d{10}$/', $id)) {
            return false;
        }

        $province = (int) substr($id, 0, 2);
        if ($province < 1 || $province > 24) {
            return false;
        }

        // Third digit identifies a natural person: must be 0-5
        if ((int) $id[2] >= 6) {
            return false;
        }

        return $this->mod10($id) === (int) $id[9];
    }

    private function isValidRuc(string $id): bool
    {
        if (! preg_match('/^\d{13}$/', $id)) {
            return false;
        }

        $province = (int) substr($id, 0, 2);
        if ($province < 1 || $province > 24) {
            return false;
        }

        // Establishment suffix must be at least 001
        if ((int) substr($id, 10, 3) < 1) {
            return false;
        }

        $thirdDigit = (int) $id[2];

        // Natural person RUC: third digit 0-5, cédula portion (first 10) is valid
        if ($thirdDigit < 6) {
            return $this->isValidCedula(substr($id, 0, 10));
        }

        // Public entity: third digit 6, mod-11 over first 9, check digit at index 9
        if ($thirdDigit === 6) {
            return $this->mod11($id, [3, 2, 7, 6, 5, 4, 3, 2], 9);
        }

        // Juridical entity: third digit 9, mod-11 over first 9, check digit at index 9
        if ($thirdDigit === 9) {
            return $this->mod11($id, [4, 3, 2, 7, 6, 5, 4, 3, 2], 9);
        }

        return false;
    }

    /**
     * Module-10 check digit used by cédula and natural-person RUC.
     */
    private function mod10(string $id): int
    {
        $coefficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $product = (int) $id[$i] * $coefficients[$i];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $check = 10 - ($sum % 10);

        return $check === 10 ? 0 : $check;
    }

    /**
     * Module-11 check digit for public (third digit 6) and juridical (third
     * digit 9) RUCs. The coefficient array length determines how many digits
     * participate; the result is compared against the digit at $checkIndex.
     *
     * @param  int[]  $coefficients
     */
    private function mod11(string $id, array $coefficients, int $checkIndex): bool
    {
        $sum = 0;

        foreach ($coefficients as $i => $coefficient) {
            $sum += (int) $id[$i] * $coefficient;
        }

        $remainder = $sum % 11;
        $check = $remainder === 0 ? 0 : 11 - $remainder;

        return $check === (int) $id[$checkIndex];
    }
}
