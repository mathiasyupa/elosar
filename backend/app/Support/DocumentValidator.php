<?php

namespace App\Support;

/**
 * Validation helpers for identity documents from different countries.
 *
 * Ecuadorian cedulas use the standard modulo-10 check with province prefix
 * validation. Other documents are validated loosely by type so the system can
 * support foreign patients without inventing or truncating numbers.
 */
class DocumentValidator
{
    public const VALID_TYPES = ['cedula', 'passport', 'foreign_id', 'other'];

    /**
     * Validate a document number according to its type and country.
     *
     * @return string|null Error message, or null when valid.
     */
    public static function validate(?string $type, ?string $country, string $number): ?string
    {
        $type = $type ?: 'cedula';
        $country = $country ?: 'EC';

        if (! in_array($type, self::VALID_TYPES, true)) {
            return 'El tipo de documento no es válido.';
        }

        if ($country !== 'EC' && $type === 'cedula') {
            return 'La cédula solo puede ser de Ecuador (EC).';
        }

        if ($number === '') {
            return 'El número de documento es obligatorio.';
        }

        return match ($type) {
            'cedula' => self::validateEcuadorianCedula($number),
            'passport' => self::validatePassport($number),
            'foreign_id', 'other' => self::validateForeignId($number),
            default => 'El tipo de documento no es válido.',
        };
    }

    public static function validateEcuadorianCedula(string $number): ?string
    {
        if (! preg_match('/^\d{10}$/', $number)) {
            return 'La cédula ecuatoriana debe tener 10 dígitos.';
        }

        $province = (int) substr($number, 0, 2);
        if ($province < 1 || $province > 24) {
            return 'La cédula ecuatoriana tiene un código de provincia inválido.';
        }

        $third = (int) $number[2];
        if ($third < 0 || $third > 6) {
            return 'La cédula ecuatoriana tiene un dígito verificador inválido.';
        }

        $coeficients = [2, 1, 2, 1, 2, 1, 2, 1, 2];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $value = (int) $number[$i] * $coeficients[$i];
            $sum += $value >= 10 ? $value - 9 : $value;
        }

        $checksum = (10 - ($sum % 10)) % 10;

        if ($checksum !== (int) $number[9]) {
            return 'La cédula ecuatoriana no supera la validación de dígito verificador.';
        }

        return null;
    }

    public static function validatePassport(string $number): ?string
    {
        if (strlen($number) > 30) {
            return 'El pasaporte no puede tener más de 30 caracteres.';
        }

        if (! preg_match('/^[a-zA-Z0-9\-]+$/', $number)) {
            return 'El pasaporte solo puede contener letras, números y guiones.';
        }

        return null;
    }

    public static function validateForeignId(string $number): ?string
    {
        if (strlen($number) > 20) {
            return 'El documento extranjero no puede tener más de 20 caracteres.';
        }

        if (! preg_match('/^[a-zA-Z0-9\-]+$/', $number)) {
            return 'El documento solo puede contener letras, números y guiones.';
        }

        return null;
    }

    /**
     * Suggest document type and country from a raw number string.
     *
     * @return array{type: string, country: string, warning: string|null}
     */
    public static function suggest(string $number): array
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $number);

        if (preg_match('/^\d{10}$/', $clean) && self::validateEcuadorianCedula($clean) === null) {
            return ['type' => 'cedula', 'country' => 'EC', 'warning' => null];
        }

        if (preg_match('/^\d{9}$/', $clean)) {
            return [
                'type' => 'cedula',
                'country' => 'EC',
                'warning' => 'Podría faltar un dígito al inicio de una cédula ecuatoriana.',
            ];
        }

        if (preg_match('/^\d{6,8}$/', $clean)) {
            return [
                'type' => 'foreign_id',
                'country' => 'VE',
                'warning' => 'Documento numérico corto; posible identificación extranjera.',
            ];
        }

        if (preg_match('/[a-zA-Z]/', $clean)) {
            return ['type' => 'passport', 'country' => null, 'warning' => null];
        }

        return ['type' => 'other', 'country' => null, 'warning' => null];
    }
}
