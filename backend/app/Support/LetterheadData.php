<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

/**
 * Shared letterhead payload for patient-facing PDFs (prescriptions, certificates).
 * Images are embedded as base64 data-URIs for dompdf compatibility.
 */
class LetterheadData
{
    /** Content margins (mm) used when the workspace has not set its own. */
    public const DEFAULT_SHEET_MARGINS = ['top' => 12, 'right' => 14, 'bottom' => 14, 'left' => 14];

    /**
     * How the designed sheet is used:
     *  - off        → no sheet, the documents draw their own letterhead.
     *  - background → the sheet is printed behind the content (digital PDF).
     *  - margins    → the sheet is NOT printed, only its margins apply: for
     *                 clinics that print on the physical pre-designed paper.
     */
    public const SHEET_MODES = ['off', 'background', 'margins'];

    /** The patient-facing documents that can each choose their paper. */
    public const DOCUMENTS = ['prescription', 'certificate', 'order'];

    /**
     * The paper a document goes out on — what the doctor picks per document:
     *  - letterhead → the system letterhead (logo and data), no sheet;
     *  - sheet      → the designed sheet printed behind the content;
     *  - preprinted → printed on the clinic's physical paper: margins only.
     */
    public const PAPERS = ['letterhead', 'sheet', 'preprinted'];

    private const PAPER_SHEET_MODES = ['letterhead' => 'off', 'sheet' => 'background', 'preprinted' => 'margins'];

    /**
     * @return array{
     *     workspace: ?Workspace,
     *     settings: array<string, mixed>,
     *     logo: ?string,
     *     headerImage: ?string,
     *     headerImageStyle: ?string,
     *     headerMode: string,
     *     sheetImage: ?string,
     *     sheetMode: string,
     *     paper: string,
     *     sheetMargins: array{top: float, right: float, bottom: float, left: float},
     *     signature: ?string,
     *     doctor: ?Doctor,
     *     showDoctorContact: bool
     * }
     */
    public static function for(?Workspace $workspace, ?Doctor $doctor, ?string $document = null): array
    {
        $settings = $workspace?->prescription_settings ?? [];
        $settings = is_array($settings) ? $settings : [];

        // "image" only takes effect when a banner was actually uploaded;
        // otherwise we always fall back to the digital (text) letterhead.
        $headerImage = self::dataUri($workspace?->header_image_path);
        $headerMode = ($settings['header_mode'] ?? 'digital') === 'image' && $headerImage
            ? 'image'
            : 'digital';

        $sheetImage = self::dataUri($workspace?->sheet_image_path);
        $paper = self::paperFor($settings, $document);
        $sheetMode = self::sheetMode(self::PAPER_SHEET_MODES[$paper], $sheetImage);

        // A designed sheet already carries the clinic's name, logo and footer:
        // drawing the system letterhead on top of it duplicates them. Clinics
        // whose sheet is only a frame can ask for it back.
        if ($sheetMode !== 'off' && ! ($settings['sheet_keeps_header'] ?? false)) {
            $headerMode = 'none';
        }

        return [
            'workspace' => $workspace,
            'settings' => $settings,
            'logo' => self::dataUri($workspace?->logo_path),
            'headerImage' => $headerImage,
            'headerImageStyle' => self::headerImageStyle($settings),
            // Width / height of the uploaded banner, to scale it to its box
            // without distorting it (dompdf cannot do both max sizes at once).
            'headerImageRatio' => self::imageRatio($workspace?->header_image_path),
            'logoRatio' => self::imageRatio($workspace?->logo_path),
            'headerMode' => $headerMode,
            // Only handed to the view when it is actually going to be printed.
            'sheetImage' => $sheetMode === 'background' ? $sheetImage : null,
            'sheetMode' => $sheetMode,
            'paper' => $paper,
            'sheetMargins' => self::sheetMargins($settings),
            'signature' => self::dataUri($doctor?->signature_path),
            'doctor' => $doctor,
            // In a clinic the paper is shared and the patient calls the clinic:
            // the doctor's own phone and email stay off it, only the cédula prints.
            'showDoctorContact' => ! ($workspace?->isClinic() ?? false),
            // What prints under the signature to reach whoever issued it: the
            // clinic's phone and email (Ajustes → Papelería) in a clinic, the
            // doctor's own in a personal practice.
            'signatureContact' => self::signatureContact($workspace, $doctor, $settings),
        ];
    }

    /**
     * Inline style that sets how tall the uploaded banner may print, in mm.
     * Null keeps each document's own default (about 90 px), which is what
     * made tall letterheads print tiny. Clamped so a typo cannot push the
     * content off the page.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function headerImageStyle(array $settings): ?string
    {
        $height = $settings['header_image_height'] ?? null;

        if (! is_numeric($height)) {
            return null;
        }

        return 'max-height: '.max(10, min(80, (int) $height)).'mm';
    }

    /**
     * The paper this document goes out on. A per-document choice
     * (`paper.prescription`…) wins; without one, the single `sheet_mode` the
     * workspace had before applies to every document, so a clinic that set up
     * its sheet earlier keeps printing exactly as it did.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function paperFor(array $settings, ?string $document): string
    {
        $choices = $settings['paper'] ?? [];
        $chosen = $document !== null && is_array($choices) ? ($choices[$document] ?? null) : null;

        if (in_array($chosen, self::PAPERS, true)) {
            return $chosen;
        }

        return match ($settings['sheet_mode'] ?? 'off') {
            'background' => 'sheet',
            'margins' => 'preprinted',
            default => 'letterhead',
        };
    }

    /**
     * A sheet that was never uploaded cannot be printed; a clinic that prints on
     * its physical paper ("margins") keeps its margins even without an image.
     */
    private static function sheetMode(string $mode, ?string $sheetImage): string
    {
        return $mode === 'background' && ! $sheetImage ? 'off' : $mode;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private static function sheetMargins(array $settings): array
    {
        $stored = $settings['sheet_margins'] ?? [];
        $stored = is_array($stored) ? $stored : [];

        $margins = [];
        foreach (self::DEFAULT_SHEET_MARGINS as $side => $default) {
            $value = $stored[$side] ?? $default;
            // Clamp: a margin wider than half the page would leave no content.
            $margins[$side] = max(0, min(100, (float) $value));
        }

        return $margins;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{phone: ?string, email: ?string}
     */
    public static function signatureContact(?Workspace $workspace, ?Doctor $doctor, array $settings): array
    {
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        if ($workspace?->isClinic()) {
            return ['phone' => $clean($settings['phone'] ?? null), 'email' => $clean($settings['email'] ?? null)];
        }

        return ['phone' => $clean($doctor?->phone), 'email' => $clean($doctor?->email)];
    }

    /**
     * «Dr. Nombre» the same way on every document. A name already carrying a
     * title ("Dra.", "MD", "Doctor") keeps only one, so it never reads
     * «Dr. Dr. …» or «MD Dra. …».
     */
    public static function doctorName(?Doctor $doctor): string
    {
        $name = trim((string) $doctor?->name);
        if ($name === '') {
            return '';
        }

        $bare = trim((string) preg_replace('/^(dra?\.?|md\.?|doctora?)\s+/iu', '', $name));

        return ($doctor?->honorific === 'Dra.' ? 'Dra. ' : 'Dr. ').$bare;
    }

    /** Width ÷ height of a stored image, or null when there is none. */
    public static function imageRatio(?string $path): ?float
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $size = @getimagesize(Storage::disk('local')->path($path));

        return $size && $size[1] > 0 ? $size[0] / $size[1] : null;
    }

    /**
     * Base64 data-URI for a letterhead image, read straight off the private
     * disk. dompdf cannot follow a signed URL, so PDF rendering reads bytes
     * rather than going through App\Support\PrivateImageUrl.
     */
    public static function dataUri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return null;
        }

        $full = $disk->path($path);
        $mime = @mime_content_type($full) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($full));
    }
}
