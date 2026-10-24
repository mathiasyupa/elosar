<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores a letterhead image (logo, banner, designed sheet) at a size dompdf can
 * draw quickly.
 *
 * A phone photo or a print-ready banner can be 4000+ px wide. dompdf embeds it
 * at full resolution on every render, and a transparent PNG without Imagick is
 * processed pixel by pixel in PHP: the live preview in Ajustes then took long
 * enough to fail. Nothing printed on an A4 needs more than ~300 dpi, so the
 * image is scaled down once, here, and stored as PNG (keeps transparency, so a
 * logo over a designed sheet shows no white box) or JPEG (photos, no alpha).
 * WebP is converted, since dompdf in production cannot always read it.
 */
class LetterheadImage
{
    /** Longest side, in px, for each kind of image. ~300 dpi at its printed size. */
    public const MAX_SIDE = [
        'logo' => 800,
        'banner' => 2400,
        'sheet' => 3508,
    ];

    /**
     * @param  'logo'|'banner'|'sheet'  $kind
     *
     * @throws ValidationException when the file cannot be decoded
     */
    public static function store(UploadedFile $file, string $kind, string $field): string
    {
        $source = self::decode($file);

        if (! $source) {
            throw ValidationException::withMessages([
                $field => 'No se pudo leer la imagen. Súbela en JPG o PNG.',
            ]);
        }

        $image = self::fit($source, self::MAX_SIDE[$kind]);
        $alpha = self::hasTransparency($image);

        ob_start();
        if ($alpha) {
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        } else {
            imagejpeg($image, null, 88);
        }
        $bytes = (string) ob_get_clean();

        $path = 'letterheads/'.Str::random(40).($alpha ? '.png' : '.jpg');
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    private static function decode(UploadedFile $file): ?GdImage
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        // imagecreatefromstring covers PNG and JPEG everywhere, and WebP when
        // GD was built with it; anything else returns false.
        $image = @imagecreatefromstring($bytes);

        return $image instanceof GdImage ? $image : null;
    }

    /** Scale down (never up) so the longest side is at most $max px. */
    private static function fit(GdImage $source, int $max): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $max / max($width, $height));

        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        if ($scale >= 1) {
            return $source;
        }

        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($w, $h);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $w, $h, $width, $height);

        return $target;
    }

    /**
     * Whether any pixel is not fully opaque. Sampled on a grid: exact enough to
     * tell a transparent logo from a photo, cheap on a 2400 px banner.
     */
    private static function hasTransparency(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $step = max(1, (int) floor(min($width, $height) / 60));

        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
