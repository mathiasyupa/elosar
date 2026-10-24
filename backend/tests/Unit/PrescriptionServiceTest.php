<?php

use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordPrescription;
use App\Models\Specialty;
use App\Models\Workspace;
use App\Services\PrescriptionService;
use App\Support\ActiveWorkspace;

/**
 * A record carrying the given prescriptions, each array being factory overrides.
 */
function recordPrescribing(array ...$prescriptions): MedicalRecord
{
    $workspace = Workspace::factory()->withPrescriptionSettings()->create();
    $doctor    = Doctor::factory()->create(['workspace_id' => $workspace->id]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Dolor de cabeza persistente',
        'diagnosis'               => 'Cefalea tensional (G44.2)',
        'treatment'               => 'Analgesia por 5 días.',
    ]);

    foreach ($prescriptions as $overrides) {
        RecordPrescription::factory()->create(
            array_merge(['medical_record_id' => $record->id], $overrides)
        );
    }

    return $record->refresh();
}

/**
 * Contents of every "Cantidad" cell of the medications table, in order. The
 * column header is a <th>, so it is not picked up here.
 *
 * @return list<string>
 */
function quantityCells(string $html): array
{
    preg_match_all('#<td class="qty">(.*?)</td>#s', $html, $matches);

    return $matches[1];
}

// ─────────────────────────────────────────────
// Basic generation
// ─────────────────────────────────────────────

it('generates a valid PDF for a complete medical record', function () {
    $workspace = Workspace::factory()->withPrescriptionSettings()->create();
    $doctor    = Doctor::factory()->create(['workspace_id' => $workspace->id]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Dolor de cabeza persistente',
        'current_illness'         => 'Cefalea tensional de 3 días de evolución.',
        'diagnosis'               => 'Cefalea tensional (G44.2)',
        'treatment'               => 'Paracetamol 500mg cada 8 horas por 5 días.',
    ]);

    $pdf    = app(PrescriptionService::class)->pdf($record);
    $output = $pdf->output();

    expect($output)->toBeString()
        ->and(substr($output, 0, 4))->toBe('%PDF');
});

it('generates a PDF even without logo or signature', function () {
    $workspace = Workspace::factory()->create([
        'logo_path'             => null,
        'prescription_settings' => null,
    ]);
    $doctor  = Doctor::factory()->create(['workspace_id' => $workspace->id, 'signature_path' => null]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Control rutinario',
        'diagnosis'               => 'Paciente sano',
        'treatment'               => 'Sin tratamiento',
    ]);

    $pdf = app(PrescriptionService::class)->pdf($record);

    expect($pdf->output())->toBeString();
});

it('generates a PDF even when the workspace has no prescription_settings', function () {
    $workspace = Workspace::factory()->create(['prescription_settings' => null]);
    $doctor    = Doctor::factory()->create(['workspace_id' => $workspace->id]);
    $patient   = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Revisión',
        'diagnosis'               => 'Sin hallazgos',
        'treatment'               => 'Observación',
    ]);

    expect(fn () => app(PrescriptionService::class)->pdf($record))->not->toThrow(Exception::class);
});

// ─────────────────────────────────────────────
// "Cantidad" column
// ─────────────────────────────────────────────

it('prints the dispensing quantity in numbers and in words', function () {
    $record = recordPrescribing(['medication_name' => 'Clonazepam', 'quantity' => 30]);

    $cells = quantityCells(app(PrescriptionService::class)->html($record));

    expect($cells)->toHaveCount(1)
        ->and($cells[0])->toContain('# 30')
        ->and($cells[0])->toContain('TREINTA');
});

it('never leaves the quantity cell blank when the prescription carries no quantity', function () {
    // Prescriptions written before `quantity` existed (migration 2026_06_20_000005
    // added it nullable). An empty cell reads as a printing defect on a signed
    // document, so the column has to say something.
    $record = recordPrescribing(['quantity' => null]);

    $cells = quantityCells(app(PrescriptionService::class)->html($record));

    expect($cells)->toHaveCount(1)
        ->and(trim(strip_tags($cells[0])))->not->toBe('')
        ->and($cells[0])->toContain('—');
});

it('fills the quantity cell of every medication, mixing set and missing quantities', function () {
    $record = recordPrescribing(
        ['quantity' => 21],
        ['quantity' => null],
        ['quantity' => 15],
    );

    $cells = quantityCells(app(PrescriptionService::class)->html($record));

    expect($cells)->toHaveCount(3);

    foreach ($cells as $cell) {
        expect(trim(strip_tags($cell)))->not->toBe('');
    }
});

// ─────────────────────────────────────────────
// Prescriptions without structured posology
// ─────────────────────────────────────────────

/**
 * Contents of every medication cell of the MSP table, in order.
 *
 * @return list<string>
 */
function medicationCells(string $html): array
{
    preg_match_all('#<td class="med">(.*?)</td>#s', $html, $matches);

    return $matches[1];
}

it('prints the patient instructions when there is no structured posology', function () {
    // From 2026-09-21 a prescription can be just a name, a quantity and free-text
    // instructions. The MSP table used to print only the composed posology line,
    // so such a prescription came out with the medication and nothing else.
    $record = recordPrescribing([
        'medication_name' => 'Betametasona crema',
        'concentration'   => null,
        'dosage'          => null,
        'frequency'       => null,
        'duration_days'   => null,
        'route'           => null,
        'quantity'        => 1,
        'instructions'    => 'Aplicar una capa fina dos veces al día por 7 días.',
    ]);

    $html = app(PrescriptionService::class)->html($record);

    // Both panels carry the instructions: the left one is the pharmacy's copy.
    expect(medicationCells($html)[0])->toContain('Aplicar una capa fina dos veces al día')
        ->and(substr_count($html, 'Aplicar una capa fina dos veces al día'))->toBeGreaterThanOrEqual(2);
});

it('does not print a dangling dash when only the instructions exist', function () {
    $record = recordPrescribing([
        'medication_name' => 'Betametasona crema',
        'dosage'          => null,
        'frequency'       => null,
        'duration_days'   => null,
        'route'           => null,
        'quantity'        => 1,
        'instructions'    => 'Aplicar dos veces al día.',
    ]);

    $html = app(PrescriptionService::class)->html($record);

    expect($html)->not->toContain('> — Aplicar dos veces al día.')
        ->and($html)->not->toContain('>— Aplicar dos veces al día.');
});

it('still prints the full posology line for a prescription that has one', function () {
    // Records written before the change keep their structured values, and
    // "repetir la última receta" carries them forward. They must keep reading
    // exactly as they did.
    $record = recordPrescribing([
        'medication_name' => 'Paracetamol',
        'concentration'   => '500mg',
        'dosage'          => '1 tableta',
        'route'           => 'oral',
        'frequency'       => 'cada 8 horas',
        'duration_days'   => 5,
        'quantity'        => 15,
        'instructions'    => 'Tomar con alimentos.',
    ]);

    $html = app(PrescriptionService::class)->html($record);

    expect($html)->toContain('1 tableta (VO), cada 8 horas, por 5 días')
        ->and($html)->toContain('Tomar con alimentos.');
});

it('prints the medication even with neither posology nor instructions', function () {
    $record = recordPrescribing([
        'medication_name' => 'Suero fisiológico',
        'dosage'          => null,
        'frequency'       => null,
        'duration_days'   => null,
        'route'           => null,
        'instructions'    => null,
        'quantity'        => 2,
    ]);

    $html = app(PrescriptionService::class)->html($record);

    expect($html)->toContain('SUERO FISIOLÓGICO')
        ->and(quantityCells($html)[0])->toContain('# 2');
});

// ─────────────────────────────────────────────
// Signature block
// ─────────────────────────────────────────────

it('signs with the primary specialty alone, not every specialty the doctor holds', function () {
    $workspace = Workspace::factory()->withPrescriptionSettings()->create();
    // DoctorFactory always sets a legacy specialty_id, so the doctor has three
    // specialties in play: the legacy FK and two pivot rows. Only the pivot's
    // primary may reach the signature.
    $legacy  = Specialty::factory()->create(['name' => 'Traumatología Deportiva']);
    $doctor  = Doctor::factory()->create([
        'workspace_id' => $workspace->id,
        'specialty_id' => $legacy->id,
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $primary   = Specialty::factory()->create(['name' => 'Ginecología y Obstetricia']);
    $secondary = Specialty::factory()->create(['name' => 'Medicina General']);
    $doctor->specialties()->attach($primary->id, ['is_primary' => true]);
    $doctor->specialties()->attach($secondary->id, ['is_primary' => false]);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Control',
        'diagnosis'               => 'Sin hallazgos',
        'treatment'               => 'Observación',
    ]);

    $html = app(PrescriptionService::class)->html($record);

    expect($html)->toContain('Ginecología y Obstetricia')
        ->and($html)->not->toContain('Medicina General')
        ->and($html)->not->toContain('Traumatología Deportiva');
});

it('signs with the legacy specialty when the doctor has no pivot rows', function () {
    // Doctors created before the doctor_specialties pivot still carry a single
    // specialty_id. primarySpecialty() falls back to it; the signature must too.
    $workspace = Workspace::factory()->withPrescriptionSettings()->create();
    $specialty = Specialty::factory()->create(['name' => 'Cardiología Intervencionista']);
    $doctor    = Doctor::factory()->create([
        'workspace_id' => $workspace->id,
        'specialty_id' => $specialty->id,
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $workspace->id]);
    app(ActiveWorkspace::class)->bind($workspace->id);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $workspace->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Control',
        'diagnosis'               => 'Sin hallazgos',
        'treatment'               => 'Observación',
    ]);

    expect(app(PrescriptionService::class)->html($record))->toContain('Cardiología Intervencionista');
});
