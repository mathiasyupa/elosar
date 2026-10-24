<?php

use App\Models\AccessLog;
use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\StudyOrder;
use App\Services\PrescriptionService;
use App\Support\LetterheadData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Papelería: the paper each document goes out on, and its live preview
|--------------------------------------------------------------------------
| Receta, certificate and study order each choose letterhead / sheet /
| preprinted. Workspaces that set up their sheet before this keep printing
| exactly as they did. The preview draws the real documents with unsaved
| settings and writes nothing.
*/

function paperHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function uploadPaperSheet(int $workspaceId): void
{
    test()->post('/api/v1/settings/sheet-image', [
        'sheet_image' => UploadedFile::fake()->image('hoja.png', 1240, 1754),
    ], paperHeaders($workspaceId))->assertOk();
}

it('lets each document choose its own paper', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    uploadPaperSheet($ws->id);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => [
            'paper' => ['prescription' => 'sheet', 'certificate' => 'letterhead', 'order' => 'preprinted'],
        ],
    ], paperHeaders($ws->id))->assertOk();

    $ws = $ws->fresh();
    $rx = LetterheadData::for($ws, $doctor, 'prescription');
    $cert = LetterheadData::for($ws, $doctor, 'certificate');
    $order = LetterheadData::for($ws, $doctor, 'order');

    expect($rx['paper'])->toBe('sheet')
        ->and($rx['sheetMode'])->toBe('background')
        ->and($rx['sheetImage'])->not->toBeNull()
        // The sheet carries the clinic's identity: no system letterhead on top.
        ->and($rx['headerMode'])->toBe('none')
        // The certificate keeps the system letterhead and no sheet at all.
        ->and($cert['sheetMode'])->toBe('off')
        ->and($cert['sheetImage'])->toBeNull()
        ->and($cert['headerMode'])->toBe('digital')
        // The order goes on the physical paper: margins only, nothing drawn.
        ->and($order['sheetMode'])->toBe('margins')
        ->and($order['sheetImage'])->toBeNull();
});

it('keeps a workspace that set up its sheet before printing exactly as it did', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    uploadPaperSheet($ws->id);

    // The old single switch, with no per-document choice saved.
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_mode' => 'background'],
    ], paperHeaders($ws->id))->assertOk();

    foreach (LetterheadData::DOCUMENTS as $document) {
        expect(LetterheadData::for($ws->fresh(), $doctor, $document)['sheetMode'])->toBe('background');
    }
});

it('rejects a paper that does not exist', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['paper' => ['prescription' => 'papiro']],
    ], paperHeaders($ws->id))->assertUnprocessable()->assertJsonValidationErrors('prescription_settings.paper.prescription');
});

it('draws the receta sheet once per half, at A5, instead of stretching an A4 behind the landscape page', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    uploadPaperSheet($ws->id);
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['paper' => ['prescription' => 'sheet']],
    ], paperHeaders($ws->id))->assertOk();

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
    ]);

    $html = app(PrescriptionService::class)->html($record->fresh());

    expect(substr_count($html, 'class="sheet-bg half'))->toBe(2)
        ->and($html)->toContain('.sheet-bg.half { width: 148.5mm; height: 210mm; }');
});

it('previews each real document with unsaved settings', function (string $document) {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    uploadPaperSheet($ws->id);

    $pdf = $this->postJson('/api/v1/settings/letterhead/preview/'.$document, [
        'prescription_settings' => ['paper' => [$document => 'sheet']],
    ], paperHeaders($ws->id))->assertOk()->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-')
        // The draft's sheet made it into the page.
        ->and($pdf)->toContain('/Subtype /Image');
})->with(['prescription', 'certificate', 'order']);

it('renders the preview even with no letterhead, logo or sheet at all', function (string $document) {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $pdf = $this->postJson('/api/v1/settings/letterhead/preview/'.$document, [
        'prescription_settings' => ['header_mode' => 'digital'],
    ], paperHeaders($ws->id))->assertOk()->getContent();

    expect(substr($pdf, 0, 5))->toBe('%PDF-');
})->with(['prescription', 'certificate', 'order']);

it('saves nothing and creates nothing while previewing', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $ws->update(['prescription_settings' => ['header' => 'Consultorio guardado']]);
    Sanctum::actingAs($user);

    $before = [
        MedicalRecord::query()->count(),
        Patient::query()->count(),
        MedicalCertificate::query()->count(),
        StudyOrder::query()->count(),
        AccessLog::query()->count(),
    ];

    foreach (LetterheadData::DOCUMENTS as $document) {
        $this->postJson('/api/v1/settings/letterhead/preview/'.$document, [
            'prescription_settings' => ['header' => 'Borrador sin guardar', 'paper' => [$document => 'preprinted']],
        ], paperHeaders($ws->id))->assertOk();
    }

    expect($ws->fresh()->prescription_settings)->toBe(['header' => 'Consultorio guardado'])
        ->and([
            MedicalRecord::query()->count(),
            Patient::query()->count(),
            MedicalCertificate::query()->count(),
            StudyOrder::query()->count(),
            AccessLog::query()->count(),
        ])->toBe($before);
});

it('only previews the three documents, and lets any member see them', function () {
    $s = makeClinicScenario();

    Sanctum::actingAs($s['ownerUser'] ?? $s['memUser']);
    $this->postJson('/api/v1/settings/letterhead/preview/invoice', ['prescription_settings' => ['header_mode' => 'digital']], paperHeaders($s['clinic']->id))
        ->assertNotFound();

    // Every member (nursing included) has settings.view: seeing the paper is read-only.
    Sanctum::actingAs($s['nurseUser']);
    $this->postJson('/api/v1/settings/letterhead/preview/prescription', ['prescription_settings' => ['header_mode' => 'digital']], paperHeaders($s['clinic']->id))
        ->assertOk();
});

it('counts as configured when any single document goes out on the clinic paper', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    expect($ws->fresh()->hasLetterhead())->toBeFalse();

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['paper' => ['prescription' => 'letterhead', 'certificate' => 'letterhead', 'order' => 'preprinted']],
    ], paperHeaders($ws->id))->assertOk();

    expect($ws->fresh()->hasLetterhead())->toBeTrue();
});

it('prints the receta as one uncut portrait page when the clinic chooses it', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
    ]);

    // Default: two halves to cut.
    expect(app(PrescriptionService::class)->html($record->fresh()))
        ->toContain('class="cut"')
        ->not->toContain('page portrait');

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['prescription_layout' => 'portrait'],
    ], paperHeaders($ws->id))->assertOk();

    $html = app(PrescriptionService::class)->html($record->fresh());

    expect($html)->toContain('page portrait')
        ->not->toContain('class="cut"')
        // Medications and indications, each once, on the same page.
        ->and(substr_count($html, 'Medicamentos.-'))->toBe(1)
        ->and(substr_count($html, 'Indicaciones.-'))->toBe(1);
});

it('rejects a receta layout that does not exist', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['prescription_layout' => 'diagonal'],
    ], paperHeaders($ws->id))->assertUnprocessable()->assertJsonValidationErrors('prescription_settings.prescription_layout');
});

it('fits a stamped receta on one sheet, in both layouts', function (string $layout) {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    // The doctor signs with a physical stamp: its box is taller than a signature.
    $doctor->forceFill(['uses_physical_stamp_signature' => true])->save();
    $ws->update(['prescription_settings' => ['prescription_layout' => $layout]]);
    Sanctum::actingAs($user);

    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'treatment' => 'CUIDADOS EN CASO.',
        'recommendations' => 'DIETA ADECUADA, EVITAR TABACO, CONSUMO DE AGUA ADECUADO.',
    ]);
    foreach ([8, 4] as $quantity) {
        $record->prescriptions()->create([
            'medication_name' => 'CLARICORT BETAMETASONA MAS LORATADINA',
            'quantity' => $quantity,
            'instructions' => 'TOMAR CADA 12 HORAS POR 4 DIAS',
        ]);
    }

    $pdf = app(PrescriptionService::class)->pdf($record->fresh());
    $pdf->output();

    expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe(1);
})->with(['landscape', 'portrait']);
