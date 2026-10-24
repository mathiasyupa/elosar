<?php

use App\Models\MedicalCertificate;
use App\Models\MedicalRecord;
use App\Models\Patient;
use Laravel\Sanctum\Sanctum;

function certApiHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

it('lists and creates a medical certificate for a patient', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $created = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type'  => MedicalCertificate::TYPE_ATTENDANCE,
        'title' => 'Constancia de atención médica',
        'body'  => 'Certifico que el/la paciente acudió a consulta médica el día de hoy.',
    ], certApiHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($created['type'])->toBe('attendance')
        ->and($created['type_label'])->toBe('Constancia de atención médica');

    $this->assertDatabaseHas('medical_certificates', [
        'id'           => $created['id'],
        'workspace_id' => $ws->id,
        'patient_id'   => $patient->id,
        'created_by'   => $doctor->id,
    ]);

    $this->getJson('/api/v1/patients/'.$patient->id.'/certificates', certApiHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.0.id', $created['id']);
});

it('generates a certificate PDF with valid header', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $ws->update([
        'prescription_settings' => [
            'header' => 'Clínica Demo',
        ],
    ]);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type'  => MedicalCertificate::TYPE_REST,
        'title' => 'Certificado de reposo',
        'body'  => 'Se recomienda reposo absoluto por 3 días.',
        'rest_days' => 3,
    ], certApiHeaders($ws->id))
        ->assertStatus(201)
        ->json('data.id');

    $response = $this->get('/api/v1/certificates/'.$id.'/pdf', certApiHeaders($ws->id));

    $response->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('issues the parameterised certificate and fills what the clinic already knows', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create([
        'workspace_id' => $ws->id,
        'city' => 'Quito',
        'phone' => '0986813118',
        'gender' => 'female',
        'birth_date' => now()->subYears(22)->toDateString(),
    ]);

    Sanctum::actingAs($user);

    $created = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type' => MedicalCertificate::TYPE_MEDICAL,
        'title' => 'Certificado médico',
        // The body is optional here: the content lives in the fields.
        'contingency_type' => 'ENFERMEDAD GENERAL',
        'company' => 'Hospital Docente de Calderón',
        'activity' => 'Interna rotativa de nutrición',
        'attended_at' => now()->toDateString(),
        'symptoms' => 'Cefalea, dolor cervical, dolor lumbar',
        'diagnosis' => 'Traumatismos superficiales múltiples de la cabeza',
        'cie10_codes' => 'S007 / S307',
        'treatment' => 'Sintomático',
        'absolute_rest' => true,
        'valid_from' => now()->toDateString(),
        'valid_until' => now()->addDays(2)->toDateString(),
    ], certApiHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($created['type'])->toBe('medical')
        ->and($created['type_label'])->toBe('Certificado médico')
        ->and($created['contingency_type'])->toBe('ENFERMEDAD GENERAL')
        ->and($created['cie10_codes'])->toBe('S007 / S307')
        ->and($created['absolute_rest'])->toBeTrue()
        // Taken from the patient's file, not retyped.
        ->and($created['hc_number'])->toBe($patient->id_number)
        ->and($created['phone'])->toBe('0986813118')
        ->and($created['city'])->toBe('Quito');

    $response = $this->get('/api/v1/certificates/'.$created['id'].'/pdf', certApiHeaders($ws->id));

    $response->assertOk();
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('spells certificate dates out in letters', function () {
    $words = App\Support\SpanishNumber::dateInWords(Illuminate\Support\Carbon::parse('2026-07-26'));

    expect($words)->toBe('veintiséis de julio del dos mil veintiséis');
});

it('previews a certificate inline', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type'  => MedicalCertificate::TYPE_FITNESS,
        'title' => 'Certificado de aptitud',
        'body'  => 'Se encuentra apto/a para actividad física moderada.',
    ], certApiHeaders($ws->id))
        ->json('data.id');

    $this->get('/api/v1/certificates/'.$id.'/preview', certApiHeaders($ws->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('duplicates an existing certificate', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $originalId = $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type'  => MedicalCertificate::TYPE_ATTENDANCE,
        'title' => 'Constancia',
        'body'  => 'Texto original.',
    ], certApiHeaders($ws->id))
        ->json('data.id');

    $copy = $this->postJson('/api/v1/certificates/'.$originalId.'/duplicate', [], certApiHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    expect($copy['id'])->not->toBe($originalId)
        ->and($copy['body'])->toBe('Texto original.');
});

it('isolates certificates between workspaces', function () {
    [, $userA, $wsA] = makeDoctorWithWorkspace();
    [$doctorB, , $wsB] = makeDoctorWithWorkspace();
    $patientB = Patient::factory()->create(['workspace_id' => $wsB->id]);
    $certB = MedicalCertificate::withoutGlobalScopes()->create([
        'workspace_id' => $wsB->id,
        'patient_id'   => $patientB->id,
        'created_by'   => $doctorB->id,
        'type'         => MedicalCertificate::TYPE_ATTENDANCE,
        'title'        => 'Secreto',
        'body'         => 'No debe verse',
        'issued_at'    => now()->toDateString(),
    ]);

    Sanctum::actingAs($userA);

    $this->getJson('/api/v1/certificates/'.$certB->id, certApiHeaders($wsA->id))
        ->assertNotFound();
});

it('links a certificate to a medical record when provided', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id'            => $ws->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'Control',
        'diagnosis'               => 'Sano',
        'treatment'               => 'N/A',
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/patients/'.$patient->id.'/certificates', [
        'type'              => MedicalCertificate::TYPE_ATTENDANCE,
        'title'             => 'Constancia',
        'body'              => 'Atención vinculada a consulta.',
        'medical_record_id' => $record->id,
    ], certApiHeaders($ws->id))
        ->assertStatus(201)
        ->assertJsonPath('data.medical_record_id', $record->id);
});

it('writes the days of rest as a number and then in words', function () {
    $data = fn (MedicalCertificate $c) => (fn () => $this->parameterisedData($c))->call(app(App\Services\CertificateService::class));

    $byDays = new MedicalCertificate(['rest_days' => 3]);
    expect($data($byDays)['restDays'])->toBe(3)
        ->and($data($byDays)['restDaysWords'])->toBe('TRES');

    // Only the dates given: the span counts both ends.
    $byDates = new MedicalCertificate(['valid_from' => '2026-10-19', 'valid_until' => '2026-10-23']);
    expect($data($byDates)['restDays'])->toBe(5)
        ->and($data($byDates)['restDaysWords'])->toBe('CINCO');

    expect($data(new MedicalCertificate())['restDays'])->toBeNull();
});

it('lets the signing doctor edit a certificate already issued', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $id = $this->postJson("/api/v1/patients/{$patient->id}/certificates", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Certificado médico', 'diagnosis' => 'Faringitis', 'cie10_codes' => 'J02.9',
    ], certApiHeaders($ws->id))->assertCreated()->json('data.id');

    $this->putJson("/api/v1/certificates/{$id}", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Certificado médico', 'diagnosis' => 'Faringitis aguda', 'cie10_codes' => 'J02.9', 'show_cie10' => false,
    ], certApiHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.diagnosis', 'Faringitis aguda')
        ->assertJsonPath('data.show_cie10', false);

    expect(MedicalCertificate::withoutGlobalScopes()->find($id)->patient_id)->toBe($patient->id);
});

it('does not let another doctor edit a certificate someone else signed', function () {
    $s = makeClinicScenario();
    $patient = Patient::factory()->create(['workspace_id' => $s['clinic']->id]);
    Sanctum::actingAs($s['ownerUser']);
    $id = $this->postJson("/api/v1/patients/{$patient->id}/certificates", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Certificado médico',
    ], certApiHeaders($s['clinic']->id))->assertCreated()->json('data.id');

    Sanctum::actingAs($s['memUser']);
    $this->putJson("/api/v1/certificates/{$id}", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Otro título',
    ], certApiHeaders($s['clinic']->id))->assertForbidden();
});

it('prints the CIE-10 only when the doctor chose to', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $html = function (bool $show) use ($patient, $ws) {
        $id = $this->postJson("/api/v1/patients/{$patient->id}/certificates", [
            'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Certificado médico', 'cie10_codes' => 'J02.9', 'show_cie10' => $show,
        ], certApiHeaders($ws->id))->assertCreated()->json('data.id');

        $rendered = null;
        \Barryvdh\DomPDF\Facade\Pdf::shouldReceive('loadView')->once()->andReturnUsing(function ($view, $data) use (&$rendered) {
            $rendered = view($view, $data)->render();
            $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
            $pdf->shouldReceive('setPaper')->andReturnSelf();

            return $pdf;
        });
        app(\App\Support\ActiveWorkspace::class)->runUnguarded(fn () => app(\App\Services\CertificateService::class)->pdf(MedicalCertificate::withoutGlobalScopes()->findOrFail($id)));

        return $rendered;
    };

    expect($html(true))->toContain('J02.9');
    expect($html(false))->not->toContain('J02.9');
});

it('hides a certificate from another clinic when editing (404)', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);
    $id = $this->postJson("/api/v1/patients/{$patient->id}/certificates", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Certificado médico',
    ], certApiHeaders($ws->id))->assertCreated()->json('data.id');

    [, $intruder, $otherWs] = makeDoctorWithWorkspace();
    Sanctum::actingAs($intruder);
    $this->putJson("/api/v1/certificates/{$id}", [
        'type' => MedicalCertificate::TYPE_MEDICAL, 'title' => 'Hackeado',
    ], certApiHeaders($otherWs->id))->assertNotFound();
});
