<?php

use App\Models\MedicalAttachment;
use App\Models\Patient;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| patients:anonymize — LOPDP right to erasure
|--------------------------------------------------------------------------
| The command scrubs the database. What it cannot scrub is the INSIDE of an
| attached file: a lab report or a scanned X-ray carries the patient's name
| printed on it. Staying silent about that is what makes an operator believe an
| erasure request is finished when it is not.
*/

/** A patient with one attachment whose file really exists on the disk. */
function patientWithAttachment(): array
{
    [$doctor] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id]);

    $path = "attachments/{$doctor->workspace_id}/{$patient->id}/informe.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 informe con el nombre impreso');

    // Sin factory: la fila se crea tal como la crea el controlador de subida.
    $attachment = MedicalAttachment::withoutGlobalScopes()->create([
        'workspace_id' => $doctor->workspace_id,
        'patient_id'   => $patient->id,
        'title'        => 'Informe de laboratorio',
        'file_path'    => $path,
    ]);

    return [$patient, $attachment, $path];
}

it('scrubs the identifying data it can reach', function () {
    [$patient] = patientWithAttachment();

    $this->artisan("patients:anonymize {$patient->id} --force")->assertSuccessful();

    $fresh = Patient::withoutGlobalScopes()->withTrashed()->find($patient->id);

    expect($fresh->name)->toBe('PACIENTE ANONIMIZADO')
        ->and($fresh->address)->toBeNull()
        ->and($fresh->deleted_at)->not->toBeNull();
});

/**
 * The point of this whole file. Before, the command printed "anonymized" and
 * said nothing about the files, so whoever was handling a verified erasure
 * request had no way to know a document still carried the name.
 */
it('warns that attached files still carry the name, and names them', function () {
    [$patient] = patientWithAttachment();

    $this->artisan("patients:anonymize {$patient->id} --force")
        ->expectsOutputToContain('1 attached file')
        ->expectsOutputToContain('Informe de laboratorio')
        ->assertSuccessful();
});

it('says nothing about files when the patient has none', function () {
    [$doctor] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $doctor->workspace_id]);

    $this->artisan("patients:anonymize {$patient->id} --force")
        ->doesntExpectOutputToContain('attached file')
        ->assertSuccessful();
});

/** Keeping the file is the default: the clinical history has a retention duty. */
it('keeps the file unless erasure is explicitly chosen', function () {
    [$patient, $attachment, $path] = patientWithAttachment();

    $this->artisan("patients:anonymize {$patient->id} --force")->assertSuccessful();

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(MedicalAttachment::withoutGlobalScopes()->find($attachment->id))->not->toBeNull();
});

/**
 * When the operator has decided erasure wins over retention for this request,
 * the row AND the bytes go. Doing it by hand on a Railway volume is the only
 * alternative, and that is worse.
 */
it('erases the file and its row when told to purge', function () {
    [$patient, $attachment, $path] = patientWithAttachment();

    $this->artisan("patients:anonymize {$patient->id} --force --purge-files")
        ->expectsOutputToContain('1 attached file')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(MedicalAttachment::withoutGlobalScopes()->find($attachment->id))->toBeNull();
});

/**
 * A row whose file is already gone must not abort the erasure half-way: the
 * remaining rows still have to go.
 */
it('finishes the purge even if a file is already missing', function () {
    [$patient, $attachment, $path] = patientWithAttachment();
    Storage::disk('local')->delete($path);

    $this->artisan("patients:anonymize {$patient->id} --force --purge-files")->assertSuccessful();

    expect(MedicalAttachment::withoutGlobalScopes()->find($attachment->id))->toBeNull();
});

/**
 * Found by running the command for real, not by the tests above — those all
 * used freshly created patients.
 *
 * The app ARCHIVES (soft-deletes) any patient with clinical history, and this
 * command soft-deletes as its last step. `Patient::find()` skips trashed rows,
 * so it could not reach the patients most likely to file an erasure request,
 * and could never be re-run on one it had already processed.
 */
it('reaches a patient who was already archived', function () {
    [$patient] = patientWithAttachment();
    $patient->delete(); // lo que hace la app con cualquier paciente con historia

    $this->artisan("patients:anonymize {$patient->id} --force")->assertSuccessful();

    $fresh = Patient::withoutGlobalScopes()->withTrashed()->find($patient->id);

    expect($fresh->name)->toBe('PACIENTE ANONIMIZADO');
});
