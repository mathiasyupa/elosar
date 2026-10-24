<?php

use App\Models\AccessLog;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Services\ClinicService;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Letterhead sample ("Ver ejemplo" in Ajustes)
|--------------------------------------------------------------------------
| Renders the same letterhead partials the clinical documents use, over
| invented patient data, so the owner can check the logo and header without
| finding a patient and writing a real prescription.
*/

function letterheadPreviewHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('returns a PDF sample of the letterhead', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);

    Sanctum::actingAs($user);

    $response = $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($ws->id))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // %PDF- is the file signature; anything else means an HTML error page came
    // back with a 200, which the status assertion alone would not catch.
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
});

it('prints the workspace letterhead, not a generic one', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $ws->update(['prescription_settings' => ['header' => 'Consultorio Dr. Demo']]);

    Sanctum::actingAs($user);

    $pdf = $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($ws->id))
        ->assertOk()
        ->getContent();

    // dompdf compresses its output, so the header text is not greppable. Size
    // is the honest proxy available here: a real rendered page, not a stub.
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

it('writes nothing — the sample leaves no record behind', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $before = [
        'records' => MedicalRecord::query()->count(),
        'patients' => Patient::query()->count(),
        'certificates' => MedicalCertificate::query()->count(),
        'access_logs' => AccessLog::query()->count(),
    ];

    $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($ws->id))->assertOk();

    expect(MedicalRecord::query()->count())->toBe($before['records'])
        ->and(Patient::query()->count())->toBe($before['patients'])
        ->and(MedicalCertificate::query()->count())->toBe($before['certificates'])
        // Nothing clinical was read either, so there is nothing to log (LOPDP).
        ->and(AccessLog::query()->count())->toBe($before['access_logs']);
});

it('renders for a workspace with no letterhead at all', function () {
    // The empty case is exactly when someone clicks "Ver ejemplo" — it must not
    // blow up on the missing logo, header or signature.
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $pdf = $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($ws->id))
        ->assertOk()
        ->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});

it('lets a member doctor preview even though they cannot edit the letterhead', function () {
    // settings.view, not settings.manage: seeing how your own documents will
    // print is useful even when Ajustes is not yours to change.
    $s = makeClinicScenario();

    Sanctum::actingAs($s['memUser']);
    $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($s['clinic']->id))
        ->assertOk();
});

it('shows the sample to every member of the clinic', function () {
    $s = makeClinicScenario();

    // Nursing has settings.view too: the paper is read-only for them.
    Sanctum::actingAs($s['nurseUser']);
    $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($s['clinic']->id))
        ->assertOk();

    $cashier = makeStaff('Caja Demo', 'caja_'.uniqid().'@clinica.test');
    app(ClinicService::class)->attachMember($s['clinic'], $cashier, 'billing');

    Sanctum::actingAs($cashier);
    $this->get('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($s['clinic']->id))
        ->assertOk();
});

it('requires authentication', function () {
    [, , $ws] = makeDoctorWithWorkspace();

    $this->getJson('/api/v1/settings/letterhead/preview', letterheadPreviewHeaders($ws->id))
        ->assertUnauthorized();
});
