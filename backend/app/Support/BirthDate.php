<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * What counts as a possible birth date. A typo such as 1087 for 1987 used to
 * be stored as is and then printed "939 años" on every list and document.
 * One rule for the form, the edit and the Excel import.
 */
final class BirthDate
{
    /** Nobody being attended today was born before this. */
    public const EARLIEST = '1900-01-01';

    public const TOO_EARLY = 'La fecha de nacimiento no puede ser anterior a 1900. Revisa el año.';

    public const IN_FUTURE = 'La fecha de nacimiento no puede ser posterior a hoy.';

    /** @return list<string> Validation rules to add after `date`. */
    public static function rules(): array
    {
        return ['after_or_equal:'.self::EARLIEST, 'before_or_equal:today'];
    }

    /**
     * @return array<string, string> Spanish messages for the field.
     */
    public static function messages(string $field = 'birth_date'): array
    {
        return [
            $field.'.after_or_equal' => self::TOO_EARLY,
            $field.'.before_or_equal' => self::IN_FUTURE,
        ];
    }

    /** Null when the date is possible; otherwise the reason, in Spanish. */
    public static function problem(CarbonInterface $date): ?string
    {
        if ($date->lt(self::EARLIEST)) {
            return self::TOO_EARLY;
        }

        return $date->isAfter(now()->endOfDay()) ? self::IN_FUTURE : null;
    }
}
