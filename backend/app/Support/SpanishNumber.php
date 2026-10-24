<?php

namespace App\Support;

/**
 * Small integer-to-Spanish-words converter for prescription quantities
 * (MSP requires the dispensed quantity in numbers AND letters). Covers 0–9999,
 * which is more than enough for a prescription.
 */
class SpanishNumber
{
    private const UNITS = ['cero', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis',
        'veintisiete', 'veintiocho', 'veintinueve'];

    private const TENS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const HUNDREDS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    public static function words(int $n): string
    {
        if ($n < 0) {
            return '';
        }
        if ($n <= 29) {
            return self::UNITS[$n];
        }
        if ($n < 100) {
            $t = intdiv($n, 10);
            $u = $n % 10;

            return self::TENS[$t].($u ? ' y '.self::UNITS[$u] : '');
        }
        if ($n === 100) {
            return 'cien';
        }
        if ($n < 1000) {
            $h = intdiv($n, 100);
            $rest = $n % 100;

            return self::HUNDREDS[$h].($rest ? ' '.self::words($rest) : '');
        }
        // 1000–9999
        $th = intdiv($n, 1000);
        $rest = $n % 1000;
        $prefix = $th === 1 ? 'mil' : self::words($th).' mil';

        return $prefix.($rest ? ' '.self::words($rest) : '');
    }

    /** @var array<int, string> */
    private const MONTHS = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * A date spelled out the way a medical certificate does it:
     * "veintiséis de julio del dos mil veintiséis". Employers and the IESS
     * expect the date in numbers AND letters, so it cannot be altered by hand.
     */
    public static function dateInWords(\DateTimeInterface $date): string
    {
        $day = (int) $date->format('j');
        $month = (int) $date->format('n');
        $year = (int) $date->format('Y');

        return sprintf(
            '%s de %s del %s',
            self::words($day),
            self::MONTHS[$month],
            self::words($year),
        );
    }
}
