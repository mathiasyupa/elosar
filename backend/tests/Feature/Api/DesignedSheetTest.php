<?php

use App\Models\MedicalCertificate;
use App\Models\Patient;
use App\Support\LetterheadData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Hoja diseñada (/settings/sheet-image)
|--------------------------------------------------------------------------
| The stationery a clinic already prints on, used as the background of its
| documents — or, when they print on the physical paper, only as the margins
| that keep the content inside the design.
*/

function sheetHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** An A4-ish PNG standing in for the clinic's designed sheet. */
function fakeSheet(): UploadedFile
{
    return UploadedFile::fake()->image('hoja.png', 1240, 1754);
}

it('uploads a designed sheet and prints it behind the document', function () {
    Storage::fake('local');
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $settings = $this->post('/api/v1/settings/sheet-image', ['sheet_image' => fakeSheet()], sheetHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($settings['sheet_image_path'])->not->toBeNull();
    Storage::disk('local')->assertExists($settings['sheet_image_path']);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => [
            'sheet_mode' => 'background',
            'sheet_margins' => ['top' => 30, 'right' => 20, 'bottom' => 25, 'left' => 20],
        ],
    ], sheetHeaders($ws->id))->assertOk();

    $id = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type' => MedicalCertificate::TYPE_MEDICAL,
        'title' => 'Certificado médico',
    ], sheetHeaders($ws->id))->assertStatus(201)->json('data.id');

    $pdf = $this->get('/api/v1/certificates/'.$id.'/pdf', sheetHeaders($ws->id))
        ->assertOk()
        ->getContent();

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        // The sheet travels inside the PDF as an embedded image.
        ->and($pdf)->toContain('/Subtype /Image');
});

it('keeps the margins but does not print the sheet when the clinic uses its own paper', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/sheet-image', ['sheet_image' => fakeSheet()], sheetHeaders($ws->id))->assertOk();
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_mode' => 'margins', 'sheet_margins' => ['top' => 40]],
    ], sheetHeaders($ws->id))->assertOk();

    $data = LetterheadData::for($ws->fresh(), $doctor);

    expect($data['sheetMode'])->toBe('margins')
        // Nothing to draw: they print on the physical sheet.
        ->and($data['sheetImage'])->toBeNull()
        ->and($data['sheetMargins']['top'])->toBe(40.0)
        // Untouched sides fall back to the defaults.
        ->and($data['sheetMargins']['left'])->toBe((float) LetterheadData::DEFAULT_SHEET_MARGINS['left'])
        // A sheet already carrying the clinic's name must not get a second one.
        ->and($data['headerMode'])->toBe('none');
});

it('lets a clinic whose sheet is only a frame keep the system letterhead', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/sheet-image', ['sheet_image' => fakeSheet()], sheetHeaders($ws->id))->assertOk();
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_mode' => 'background', 'sheet_keeps_header' => true],
    ], sheetHeaders($ws->id))->assertOk();

    expect(LetterheadData::for($ws->fresh(), $doctor)['headerMode'])->toBe('digital');
});

it('never prints a background that was never uploaded', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    Sanctum::actingAs($user);

    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_mode' => 'background'],
    ], sheetHeaders($ws->id))->assertOk();

    $data = LetterheadData::for($ws->fresh(), $doctor);

    expect($data['sheetMode'])->toBe('off')
        ->and($data['sheetImage'])->toBeNull()
        // With no sheet the document draws its own letterhead as always.
        ->and($data['headerMode'])->toBe('digital');
});

it('turns the background off when the sheet is deleted', function () {
    Storage::fake('local');
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();

    Sanctum::actingAs($user);

    $this->post('/api/v1/settings/sheet-image', ['sheet_image' => fakeSheet()], sheetHeaders($ws->id))->assertOk();
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_mode' => 'background'],
    ], sheetHeaders($ws->id))->assertOk();

    $this->deleteJson('/api/v1/settings/sheet-image', [], sheetHeaders($ws->id))->assertOk();

    $fresh = $ws->fresh();
    expect($fresh->sheet_image_path)->toBeNull()
        ->and($fresh->prescription_settings['sheet_mode'])->toBe('off')
        ->and(LetterheadData::for($fresh, $doctor)['headerMode'])->toBe('digital');
});

it('rejects an absurd margin and only lets the owner configure the sheet', function () {
    Storage::fake('local');
    $s = makeClinicScenario();
    $headers = sheetHeaders($s['clinic']->id);

    // Half a metre of margin would leave no page.
    Sanctum::actingAs($s['ownerUser']);
    $this->putJson('/api/v1/settings/letterhead', [
        'prescription_settings' => ['sheet_margins' => ['top' => 500]],
    ], $headers)->assertStatus(422);

    // An employed doctor cannot change the clinic's stationery.
    Sanctum::actingAs($s['memUser']);
    $this->post('/api/v1/settings/sheet-image', ['sheet_image' => fakeSheet()], $headers)->assertForbidden();
    $this->deleteJson('/api/v1/settings/sheet-image', [], $headers)->assertForbidden();
});
