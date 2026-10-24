<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLetterheadRequest;
use App\Http\Requests\UpdateWorkspaceSettingsRequest;
use App\Http\Resources\WorkspaceSettingsResource;
use App\Services\DocumentPreviewService;
use App\Services\Sri\SriDiagnostics;
use App\Support\ActiveWorkspace;
use App\Support\LetterheadData;
use App\Support\LetterheadImage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings and fiscal (SRI) configuration of the ACTIVE workspace (Ajustes
 * screen). Reading (show) is gated by `permission:settings.view`; every write
 * route is gated by `permission:settings.manage`, which the permission matrix
 * grants only to the workspace owner (any account type — a nurse-owner
 * configures membrete/SRI just like a doctor-owner). No per-method owner check
 * is needed: authorization is enforced by the middleware, and owner-only fields
 * (invite_code) are filtered in WorkspaceSettingsResource.
 */
class SettingsController extends Controller
{
    public function show(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        return WorkspaceSettingsResource::make($activeWorkspace->current())->response();
    }

    /**
     * Sample PDF for "Ver ejemplo" in Ajustes → Membrete e impresión.
     *
     * Checking how the letterhead prints used to mean finding a real patient,
     * writing a real prescription and opening its PDF. This renders the same
     * letterhead partials the clinical documents use, over invented patient
     * data, so nothing is written anywhere and no patient is involved.
     *
     * Gated by settings.view (not manage): a doctor who cannot edit the
     * letterhead still benefits from seeing what their documents will look like.
     */
    public function previewLetterhead(ActiveWorkspace $activeWorkspace): Response
    {
        $workspace = $activeWorkspace->current();

        // The signature and the professional registries come from whoever is
        // looking, so the sample shows THEIR document, not a generic one.
        $data = array_merge(LetterheadData::for($workspace, Auth::user()?->doctor), [
            'patient' => self::samplePatient(),
            'date' => now(),
        ]);

        return Pdf::loadView('letterhead.sample', $data)
            ->setPaper('a4')
            ->stream('ejemplo-membrete.pdf');
    }

    /**
     * The real receta, certificate or study order, drawn with the settings the
     * owner is still editing (`prescription_settings` in the body, not saved),
     * for the live preview beside the form. Same validation as saving, so the
     * preview can never show something the save would reject.
     */
    public function previewDocument(
        UpdateLetterheadRequest $request,
        string $document,
        ActiveWorkspace $activeWorkspace,
        DocumentPreviewService $previews,
    ): Response {
        $draft = $request->validated()['prescription_settings'];

        return $previews->pdf($activeWorkspace->current(), Auth::user()?->doctor, $document, $draft)
            ->stream('muestra-'.$document.'.pdf');
    }

    /**
     * Stand-in for the patient block of the letterhead. The partial only reads
     * name / id_number / city, so a plain object is enough — building a real
     * Patient would mean touching the database for a preview.
     *
     * Deliberately obvious as fiction: the cédula is not a valid Ecuadorian one
     * (its check digit does not verify), so the sample can never be mistaken for
     * a real record even if the watermark is cropped out.
     */
    private static function samplePatient(): object
    {
        return new class
        {
            public string $name = 'María Ejemplo Pérez';

            public string $id_number = '0000000000';

            public string $city = 'Quito';
        };
    }

    public function update(
        UpdateWorkspaceSettingsRequest $request,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        $workspace = $activeWorkspace->current();
        $workspace->update($request->validated());

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /**
     * Upload the SRI electronic-signature certificate (.p12) and its password.
     * The file is stored on the PRIVATE `local` disk — the exact place
     * SriCertificateLoader reads from — and the password is persisted via the
     * model's `encrypted` cast. This only stores the credential; the SRI
     * authorization pipeline is untouched and runs unchanged once a real BCE
     * certificate is provided.
     */
    public function uploadCertificate(Request $request, ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        $request->validate([
            // `extensions` (client extension) instead of `mimes` (content sniffing):
            // fileinfo reports most PKCS#12 files as application/octet-stream, so
            // `mimes:p12,pfx` rejected perfectly valid BCE certificates.
            'certificate' => ['required', 'file', 'extensions:p12,pfx', 'max:5120'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Real check: the file must actually open as PKCS#12 with this password.
        // Verifying at upload time surfaces a wrong password here instead of
        // silently rejecting every voucher later, when SriCertificateLoader fails
        // inside the queued job and the emitter has no idea why.
        $this->assertOpensAsPkcs12($request);

        // Replace any previous certificate file.
        if ($workspace->sri_certificate_path && Storage::disk('local')->exists($workspace->sri_certificate_path)) {
            Storage::disk('local')->delete($workspace->sri_certificate_path);
        }

        $path = $request->file('certificate')->store('sri/certificates', 'local');

        $workspace->update([
            'sri_certificate_path' => $path,
            'sri_certificate_password' => $request->string('password'),
        ]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /**
     * Diagnoses the workspace's SRI setup and — in the test environment only —
     * files a throwaway voucher to find out what the SRI actually thinks of it.
     *
     * Exists because rejections like "56 ESTABLECIMIENTO CERRADO" live in the
     * SRI's records, not ours: no form can predict them, and without this the
     * emitter only finds out by issuing a real invoice and having it bounce.
     *
     * Runs synchronously — it takes 7-15 s and the user is watching a spinner.
     * SriDiagnostics never throws, so a broken setup comes back as failed steps
     * rather than a 500.
     */
    public function checkSri(ActiveWorkspace $activeWorkspace, SriDiagnostics $diagnostics): JsonResponse
    {
        return response()->json([
            'data' => $diagnostics->run($activeWorkspace->current()),
        ]);
    }

    /**
     * Rejects the upload unless the file really is a PKCS#12 bundle that opens
     * with the supplied password — the exact operation SriCertificateLoader
     * performs when signing a voucher.
     *
     * @throws ValidationException
     */
    private function assertOpensAsPkcs12(Request $request): void
    {
        $contents = (string) file_get_contents($request->file('certificate')->getRealPath());
        $certs = [];

        if (openssl_pkcs12_read($contents, $certs, (string) $request->string('password'))) {
            return;
        }

        throw ValidationException::withMessages([
            'certificate' => 'No se pudo abrir el certificado. Verifica que sea un archivo .p12 o .pfx válido '
                .'y que la contraseña sea la correcta.',
        ]);
    }

    /**
     * Update prescription letterhead texts. Logo is managed via uploadLogo /
     * deleteLogo.
     */
    public function updateLetterhead(
        UpdateLetterheadRequest $request,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        $workspace = $activeWorkspace->current();

        $incoming = $request->validated()['prescription_settings'];

        $workspace->update([
            'prescription_settings' => array_merge($workspace->prescription_settings ?? [], $incoming),
        ]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /** Upload workspace logo for prescription/certificate PDFs. */
    public function uploadLogo(Request $request, ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        if ($workspace->logo_path && Storage::disk('local')->exists($workspace->logo_path)) {
            Storage::disk('local')->delete($workspace->logo_path);
        }

        $path = LetterheadImage::store($request->file('logo'), 'logo', 'logo');
        $workspace->update(['logo_path' => $path]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /** Remove the workspace logo. */
    public function deleteLogo(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        if ($workspace->logo_path && Storage::disk('local')->exists($workspace->logo_path)) {
            Storage::disk('local')->delete($workspace->logo_path);
        }

        $workspace->update(['logo_path' => null]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /** Upload a pre-designed letterhead banner (used when header_mode = 'image'). */
    public function uploadHeaderImage(Request $request, ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        $request->validate([
            'header_image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        if ($workspace->header_image_path && Storage::disk('local')->exists($workspace->header_image_path)) {
            Storage::disk('local')->delete($workspace->header_image_path);
        }

        $path = LetterheadImage::store($request->file('header_image'), 'banner', 'header_image');
        $workspace->update(['header_image_path' => $path]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /** Remove the letterhead banner. */
    public function deleteHeaderImage(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        if ($workspace->header_image_path && Storage::disk('local')->exists($workspace->header_image_path)) {
            Storage::disk('local')->delete($workspace->header_image_path);
        }

        $workspace->update(['header_image_path' => null]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /**
     * Upload the designed A4 sheet the clinic already prints on. It goes behind
     * the content of every document (sheet_mode = 'background'), or is only used
     * to set the margins when they print on the physical paper ('margins').
     */
    public function uploadSheetImage(Request $request, ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        $request->validate([
            // A full page carries more detail than a banner, hence the bigger cap.
            'sheet_image' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        if ($workspace->sheet_image_path && Storage::disk('local')->exists($workspace->sheet_image_path)) {
            Storage::disk('local')->delete($workspace->sheet_image_path);
        }

        $path = LetterheadImage::store($request->file('sheet_image'), 'sheet', 'sheet_image');
        $workspace->update(['sheet_image_path' => $path]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }

    /** Remove the designed sheet (and stop printing it). */
    public function deleteSheetImage(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $workspace = $activeWorkspace->current();

        if ($workspace->sheet_image_path && Storage::disk('local')->exists($workspace->sheet_image_path)) {
            Storage::disk('local')->delete($workspace->sheet_image_path);
        }

        $settings = $workspace->prescription_settings ?? [];
        // Without an image there is nothing to print behind the content.
        if (($settings['sheet_mode'] ?? 'off') === 'background') {
            $settings['sheet_mode'] = 'off';
        }

        $workspace->update([
            'sheet_image_path' => null,
            'prescription_settings' => $settings,
        ]);

        return WorkspaceSettingsResource::make($workspace->fresh())->response();
    }
}
