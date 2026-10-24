<?php

namespace App\Support;

/**
 * Decides how a receta fills its page before dompdf draws it.
 *
 * The page has two parts: the top (letterhead, patient data, medications or
 * indications) and a foot strip with the observations beside the signature,
 * sitting on the bottom of the sheet. dompdf cannot measure a block and then
 * move another, so the template gives the top a fixed height and the foot
 * lands under it. That only works when the content fits: a fixed area that
 * overflows is split across two or three pages. This estimates both parts
 * from what will be printed and picks the largest type that fits (from medium down), falling
 * back to the natural flow (no fixed height) when even the tight type does not.
 *
 * The figures are rough and calibrated on the real templates (DejaVu Sans):
 * a wrong guess costs some empty space, never a receta on a second sheet.
 */
class PrescriptionLayout
{
    public const PX_TO_MM = 0.2646;

    /** Body type (px) per density: landscape halves and the uncut portrait A4. */
    private const FONT = ['large' => 11.0, 'medium' => 10.0, 'compact' => 9.0, 'tight' => 7.5];

    private const FONT_PORTRAIT = ['large' => 13.0, 'medium' => 11.5, 'compact' => 10.0, 'tight' => 7.5];

    /** Signature image height (px) per density, as the stylesheet draws it. */
    private const SIGNATURE_IMAGE = ['large' => 48, 'medium' => 42, 'compact' => 36, 'tight' => 28];

    private const SIGNATURE_IMAGE_PORTRAIT = ['large' => 60, 'medium' => 52, 'compact' => 44, 'tight' => 28];

    /** `.physical-signature-space` in prescription.blade.php: keep both in step. */
    public const STAMP_SPACE_PX = 118;

    public const STAMP_PADDING_PX = 82;

    /** Usable text width (mm): half a landscape A4, or the whole portrait one. */
    private const WIDTH = ['landscape' => 128.0, 'portrait' => 186.0];

    /** Pessimism on the estimate: dompdf's tables add padding it cannot see. */
    private const SAFETY = 1.02;

    /** Share of that width the observations take in the foot strip. */
    public const OBSERVATIONS_SHARE = 0.58;

    /**
     * @param  list<array{name: string, brand: ?string, ind: ?string, extra: ?string, ctrl: bool}>  $items
     * @param  list<string>  $observations  each observation line as printed
     * @return array{density: 'large'|'medium'|'compact'|'tight', fill: bool, top: ?float}
     */
    public static function choose(
        array $items,
        array $observations,
        int $diagnosisRows,
        bool $portrait,
        float $headerMm,
        float $availableMm,
        bool $hasSignatureImage,
        bool $usesPhysicalStampSignature = false,
    ): array {
        // Medium is the largest a receta prints: clinics found the large type too big
        // (2026-10-22). 'large' stays defined so the template keeps its sizes.
        foreach (['medium', 'compact', 'tight'] as $density) {
            $top = self::top($items, $diagnosisRows, $portrait, $headerMm, $density);
            $foot = self::foot($observations, $portrait, $density, $hasSignatureImage, $usesPhysicalStampSignature);

            if (($top + $foot) * self::SAFETY <= $availableMm) {
                return ['density' => $density, 'fill' => true, 'top' => round($availableMm - $foot, 1)];
            }
        }

        // Longer than any single sheet holds: the tightest type, flowing.
        return ['density' => 'tight', 'fill' => false, 'top' => null];
    }

    /**
     * Letterhead, patient data and the medications (left half / portrait) or
     * indications (right half), in mm.
     *
     * @param  list<array{name: string, brand: ?string, ind: ?string, extra: ?string, ctrl: bool}>  $items
     */
    public static function top(array $items, int $diagnosisRows, bool $portrait, float $headerMm, string $density): float
    {
        $line = self::line($portrait, $density);
        $wrap = self::wrapper($portrait, $density, 1.0);
        $gap = $density === 'tight' ? 1.5 : ($density === 'compact' ? 2.5 : 3.5);

        // Code, the four fixed data rows and the diagnosis.
        $data = 7 + (4 + max(1, $diagnosisRows)) * ($line + 0.8);

        $prescription = $line + 4;
        $indications = $line + 4;
        foreach ($items as $item) {
            $lines = 1 + ($item['brand'] ? 1 : 0) + max(1, $wrap($item['ind'])) + ($item['ctrl'] ? 1 : 0);
            $prescription += $lines * $line + $gap;

            $indLines = 1 + ($item['brand'] ? 1 : 0) + max(1, $wrap(trim(($item['ind'] ?? '').' '.($item['extra'] ?? ''))));
            $indications += $indLines * $line + $gap;
        }

        $body = $portrait
            ? $prescription + $indications + 4
            : max($prescription, $indications); // each half carries one of them

        return $headerMm + $data + $body;
    }

    /**
     * The foot strip: observations beside the signature, whichever is taller.
     *
     * @param  list<string>  $observations
     */
    public static function foot(array $observations, bool $portrait, string $density, bool $hasSignatureImage, bool $usesPhysicalStampSignature = false): float
    {
        $line = self::line($portrait, $density);
        $wrap = self::wrapper($portrait, $density, self::OBSERVATIONS_SHARE);

        $obs = $line + 3; // the section title
        foreach ($observations as $text) {
            $obs += max(1, $wrap($text)) * $line;
        }

        $image = $hasSignatureImage
            ? ($portrait ? self::SIGNATURE_IMAGE_PORTRAIT : self::SIGNATURE_IMAGE)[$density] * self::PX_TO_MM
            : 0.0;
        // Rule, «Dr. …», the title and the cédula, plus the strip's top margin.
        // The stamp box is what the stylesheet draws: its height plus its top
        // padding (content-box). Guessing 32 mm for its real ~53 mm pushed the
        // foot onto a second sheet and split the receta (2026-10-18).
        $signature = $usesPhysicalStampSignature
            ? (self::STAMP_SPACE_PX + self::STAMP_PADDING_PX) * self::PX_TO_MM + 4
            : $image + 2 + 3 * $line + 4;

        return max($obs, $signature) + 4;
    }

    private static function line(bool $portrait, string $density): float
    {
        return ($portrait ? self::FONT_PORTRAIT : self::FONT)[$density] * 1.5 * self::PX_TO_MM;
    }

    /** @return \Closure(?string): int lines a text wraps into */
    private static function wrapper(bool $portrait, string $density, float $share): \Closure
    {
        $font = ($portrait ? self::FONT_PORTRAIT : self::FONT)[$density];
        $width = self::WIDTH[$portrait ? 'portrait' : 'landscape'] * $share;
        $perLine = max(20, (int) floor($width / ($font * 0.56 * self::PX_TO_MM)));

        return fn (?string $text) => $text === null || trim($text) === '' ? 0 : (int) ceil(mb_strlen($text) / $perLine);
    }
}
