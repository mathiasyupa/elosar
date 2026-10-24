<?php

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\RecordPrescription;
use App\Models\Specialty;
use App\Models\Workspace;
use Laravel\Sanctum\Sanctum;

function recordHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

function grantConsultationWriterGynecoCapability(Doctor $doctor): void
{
    $specialty = Specialty::query()->firstOrCreate(['name' => 'Ginecología y Obstetricia']);
    $specialty->capabilities = [Specialty::CAPABILITY_GYNECO_OBSTETRICS];
    $specialty->save();

    $doctor->specialties()->syncWithoutDetaching([$specialty->id => ['is_primary' => false]]);
}

function gynecoConsultationScenario(): array
{
    $scenario = makeClinicScenario();
    grantConsultationWriterGynecoCapability($scenario['ownerDoctor']);

    return $scenario;
}

// ─────────────────────────────────────────────
// Create a full consultation
// ─────────────────────────────────────────────

it('creates a full consultation with vitals, diagnoses and prescriptions', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $payload = [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Dolor abdominal de 3 días',
        'diagnosis' => 'Gastritis aguda',
        'treatment' => 'Omeprazol y dieta blanda',
        'vitals' => [
            'weight_kg' => 80,
            'height_cm' => 200, // 2.0 m → BMI = 80 / 4 = 20.00
            'heart_rate' => 72,
        ],
        'diagnoses' => [
            ['cie10_code' => 'K29.7', 'cie10_description' => 'Gastritis, no especificada', 'type' => 'primary'],
        ],
        'prescriptions' => [
            ['medication_name' => 'Omeprazol', 'concentration' => '20mg', 'dosage' => '1 cápsula', 'route' => 'oral', 'frequency' => 'cada 24h', 'duration_days' => 14, 'quantity' => 14],
        ],
    ];

    $data = $this->postJson('/api/v1/records', $payload, recordHeaders($ws->id))
        ->assertStatus(201)
        ->json('data');

    // Parent persisted with the correct workspace and authoring doctor.
    $this->assertDatabaseHas('medical_records', [
        'id' => $data['id'],
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
    ]);

    // Children persisted through the scoped parent.
    expect($data['vitals'])->toHaveCount(1)
        ->and($data['diagnoses'])->toHaveCount(1)
        ->and($data['prescriptions'])->toHaveCount(1);

    // BMI computed server-side: 80 / (2.0^2) = 20.00.
    expect((float) $data['vitals'][0]['bmi'])->toBe(20.0);

    $this->assertDatabaseHas('record_diagnoses', [
        'medical_record_id' => $data['id'],
        'cie10_code' => 'K29.7',
    ]);
    $this->assertDatabaseHas('record_prescriptions', [
        'medical_record_id' => $data['id'],
        'medication_name' => 'Omeprazol',
    ]);
});

it('stores MSP prescription fields and renders the receta PDF', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Prurito genital',
        'diagnosis' => 'Vaginitis',
        'treatment' => 'Tratamiento tópico',
        'recommendations' => 'Higiene diaria',
        'alarm_signs' => 'Fiebre o sangrado',
        'controlled_prescription_no' => 'L3B-F69-N204',
        'prescriptions' => [
            ['medication_name' => 'Crema vaginal', 'generic_name' => 'Colpoestriol', 'dosage' => 'Aplicar', 'frequency' => 'cada noche', 'duration_days' => 7, 'quantity' => 1, 'is_controlled' => true],
        ],
    ], recordHeaders($ws->id))->assertStatus(201)->json('data');

    expect($data['recommendations'])->toBe('Higiene diaria')
        ->and($data['controlled_prescription_no'])->toBe('L3B-F69-N204')
        ->and($data['prescriptions'][0]['generic_name'])->toBe('Colpoestriol')
        ->and($data['prescriptions'][0]['quantity'])->toBe(1);

    $this->assertDatabaseHas('record_prescriptions', [
        'medical_record_id' => $data['id'],
        'generic_name' => 'Colpoestriol',
        'quantity' => 1,
    ]);

    // The MSP receta PDF renders without error.
    $this->get("/api/v1/records/{$data['id']}/prescription", recordHeaders($ws->id))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('includes prescriptions in the patient records list (repeat-prescription contract)', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Cefalea',
        'diagnosis' => 'Cefalea tensional',
        'treatment' => 'Analgesia',
        'prescriptions' => [
            ['medication_name' => 'Paracetamol', 'concentration' => '500 mg', 'dosage' => '1 tableta', 'route' => 'oral', 'frequency' => 'cada 8 horas', 'duration_days' => 5, 'quantity' => 15],
        ],
    ], recordHeaders($ws->id))->assertStatus(201);

    // The list endpoint must carry prescriptions so the web app can offer
    // "repeat the patient's last prescription" without N detail fetches.
    $this->getJson("/api/v1/patients/{$patient->id}/records", recordHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.0.prescriptions.0.medication_name', 'Paracetamol')
        ->assertJsonPath('data.0.prescriptions.0.frequency', 'cada 8 horas');
});

it('rejects a consultation missing a required clinical field', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        // diagnosis and treatment are NOT NULL / required.
    ], recordHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['diagnosis', 'treatment']);
});

it('requires a quantity for every prescribed medication', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Dolor',
        'diagnosis' => 'Cefalea',
        'treatment' => 'Analgesia',
        'prescriptions' => [
            // quantity omitted → now required.
            ['medication_name' => 'Paracetamol', 'dosage' => '1 tableta', 'frequency' => 'cada 8h'],
        ],
    ], recordHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prescriptions.0.quantity']);
});

it('accepts a prescription with only a name, a quantity and instructions', function () {
    // Recetar una crema: el médico escribe el nombre, cuánto se dispensa y cómo
    // se usa. La posología estructurada (dosage/frequency) dejó de pedirse en
    // pantalla el 2026-09-21, así que el servidor no puede seguir exigiéndola.
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Lesión en el antebrazo',
        'diagnosis' => 'Dermatitis de contacto',
        'treatment' => 'Tópico',
        'prescriptions' => [
            [
                'medication_name' => 'Betametasona crema',
                'quantity' => 1,
                'instructions' => 'Aplicar una capa fina dos veces al día por 7 días.',
            ],
        ],
    ], recordHeaders($ws->id))->assertCreated();

    $prescription = RecordPrescription::query()
        ->where('medical_record_id', $response->json('data.id'))
        ->sole();

    expect($prescription->medication_name)->toBe('Betametasona crema')
        ->and($prescription->quantity)->toBe(1)
        ->and($prescription->instructions)->toBe('Aplicar una capa fina dos veces al día por 7 días.')
        ->and($prescription->dosage)->toBeNull()
        ->and($prescription->frequency)->toBeNull();
});

it('still requires the dispensing quantity on a prescription without posology', function () {
    // `quantity` es la "cantidad a dispensar" del MSP y ya hubo un defecto
    // histórico con esa columna saliendo en blanco en la receta impresa.
    // Relajar dosage/frequency no la arrastra.
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Lesión en el antebrazo',
        'diagnosis' => 'Dermatitis de contacto',
        'treatment' => 'Tópico',
        'prescriptions' => [
            ['medication_name' => 'Betametasona crema', 'instructions' => 'Dos veces al día.'],
        ],
    ], recordHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['prescriptions.0.quantity']);
});

it('accepts updating a consultation with a prescription that has no posology', function () {
    // El PUT tiene su propio juego de reglas inline, separado del FormRequest
    // del POST. Relajar uno y no el otro deja el 422 vivo justo en el camino
    // que usa el botón "Descargar receta" (resync antes de generar el PDF).
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'reason_for_consultation' => 'Lesión en el antebrazo',
        'status' => 'draft',
    ]);

    $this->putJson("/api/v1/records/{$record->id}", [
        'finalize' => false,
        'prescriptions' => [
            [
                'medication_name' => 'Betametasona crema',
                'quantity' => 1,
                'instructions' => 'Aplicar dos veces al día.',
            ],
        ],
    ], recordHeaders($ws->id))->assertOk();

    expect(RecordPrescription::where('medical_record_id', $record->id)->sole()->dosage)->toBeNull();
});

// ─────────────────────────────────────────────
// Save-as-you-go (the encounter's «Guardar» + autosave)
// ─────────────────────────────────────────────

it('clears a text field when an update sends it as null', function () {
    // Autosave re-sends the form: a field the doctor emptied must be emptied
    // on the server too, not silently kept from the previous save.
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'reason_for_consultation' => 'Cefalea',
        'current_illness' => 'Dolor frontal de dos días',
        'recommendations' => 'Hidratación',
        'follow_up_date' => now()->addWeek()->toDateString(),
        'status' => 'completed',
    ]);

    $this->putJson("/api/v1/records/{$record->id}", [
        'finalize' => true,
        'current_illness' => null,
        'recommendations' => null,
        'follow_up_date' => null,
    ], recordHeaders($ws->id))->assertOk();

    $record->refresh();
    expect($record->current_illness)->toBeNull()
        ->and($record->recommendations)->toBeNull()
        ->and($record->follow_up_date)->toBeNull()
        ->and($record->reason_for_consultation)->toBe('Cefalea');
});

it('keeps the fields an update does not mention', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $record = MedicalRecord::withoutGlobalScopes()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'created_by' => $doctor->id,
        'reason_for_consultation' => 'Cefalea',
        'treatment' => 'Paracetamol',
        'status' => 'completed',
    ]);
    $record->diagnoses()->create(['cie10_code' => 'R51', 'cie10_description' => 'Cefalea', 'type' => 'primary']);

    $this->putJson("/api/v1/records/{$record->id}", [
        'finalize' => true,
        'current_illness' => 'Mejora parcial',
    ], recordHeaders($ws->id))->assertOk();

    $record->refresh();
    expect($record->treatment)->toBe('Paracetamol')
        ->and($record->diagnoses()->pluck('cie10_code')->all())->toBe(['R51']);
});

it('saving a finished visit again never opens a second appointment', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    Sanctum::actingAs($user);

    $id = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'finalize' => true,
        'closed_with_override' => true,
    ], recordHeaders($ws->id))->assertCreated()->json('data.id');

    foreach (['Primera nota', 'Segunda nota'] as $note) {
        $this->putJson("/api/v1/records/{$id}", [
            'finalize' => true,
            'current_illness' => $note,
        ], recordHeaders($ws->id))->assertOk();
    }

    $record = MedicalRecord::withoutGlobalScopes()->findOrFail($id);
    expect($record->status)->toBe('completed')
        ->and($record->current_illness)->toBe('Segunda nota')
        ->and($record->appointment_id)->not->toBeNull()
        ->and(Appointment::withoutGlobalScopes()->where('patient_id', $patient->id)->count())->toBe(1);
});

// ─────────────────────────────────────────────
// Isolation
// ─────────────────────────────────────────────

it('returns 404 when creating a consultation for a foreign-workspace patient', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id]);

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $foreignPatient->id,
        'reason_for_consultation' => 'Control',
        'diagnosis' => 'Sano',
        'treatment' => 'Ninguno',
    ], recordHeaders($ws->id))
        ->assertNotFound();

    $this->assertDatabaseMissing('medical_records', ['patient_id' => $foreignPatient->id]);
});

// ─────────────────────────────────────────────
// Closure marks the linked appointment completed
// ─────────────────────────────────────────────

it('closes a consultation and marks the linked appointment completed', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $appointment = Appointment::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'status' => 'scheduled',
    ]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'appointment_id' => $appointment->id,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$record->id}/close", [], recordHeaders($ws->id))
        ->assertOk();

    expect($appointment->fresh()->status)->toBe('completed');
});

// ─────────────────────────────────────────────
// Typed structured exam
// ─────────────────────────────────────────────

it('stores a typed structured exam (gynecological) on the consultation', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    grantConsultationWriterGynecoCapability($doctor);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control ginecológico',
        'diagnosis' => 'Sana',
        'treatment' => 'Control anual',
        'consultation_type' => 'gynecological',
        'structured_exam' => [
            'fum' => '2026-05-01',
            'gestas' => '2',
            'metodo_anticonceptivo' => 'DIU',
        ],
    ], recordHeaders($ws->id))->assertStatus(201)->json('data');

    // La versión vigente, no un literal: el punto del test es que la exploración
    // hace ida y vuelta y que el servidor la sella, no en qué número va la
    // plantilla — que sube cada vez que se le añaden campos.
    $current = app(App\Services\Clinical\ClinicalTemplateRegistry::class)->currentVersion('gynecological');

    expect($data['consultation_type'])->toBe('gynecological')
        ->and($data['structured_exam_template_version'])->toBe($current)
        ->and($data['structured_exam']['gestas'])->toBe('2');

    $this->assertDatabaseHas('medical_records', [
        'id' => $data['id'],
        'consultation_type' => 'gynecological',
        'structured_exam_template_version' => $current,
    ]);
});

it('assigns the curated template version and rejects client-controlled fields', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    grantConsultationWriterGynecoCapability($doctor);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $payload = [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control ginecológico',
        'diagnosis' => 'Sana',
        'treatment' => 'Control anual',
        'consultation_type' => 'gynecological',
        'structured_exam' => ['fum' => '2026-05-01'],
    ];

    $this->postJson('/api/v1/records', $payload, recordHeaders($ws->id))
        ->assertCreated()
        ->assertJsonPath(
            'data.structured_exam_template_version',
            app(App\Services\Clinical\ClinicalTemplateRegistry::class)->currentVersion('gynecological'),
        );

    $this->postJson('/api/v1/records', [
        ...$payload,
        'structured_exam_template_version' => 999,
    ], recordHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['structured_exam_template_version']);
});

it('rejects exam fields outside the active template without changing a completed record', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    grantConsultationWriterGynecoCapability($doctor);
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control ginecológico',
        'diagnosis' => 'Sana',
        'treatment' => 'Control anual',
        'consultation_type' => 'gynecological',
        'structured_exam' => ['fum' => '2026-05-01'],
    ], recordHeaders($ws->id))->assertCreated()->json('data');

    $this->putJson("/api/v1/records/{$record['id']}", [
        'structured_exam' => ['fum' => '2026-05-01', 'clave_inventada' => 'No debe entrar'],
    ], recordHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['structured_exam']);

    expect(MedicalRecord::findOrFail($record['id'])->structured_exam)->toBe(['fum' => '2026-05-01']);

    $this->putJson("/api/v1/records/{$record['id']}", [
        'consultation_type' => 'pediatric',
    ], recordHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['consultation_type']);
});

it('rejects an invalid consultation_type', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'X',
        'diagnosis' => 'Y',
        'treatment' => 'Z',
        'consultation_type' => 'invalid_type',
    ], recordHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consultation_type']);
});

it('closing a consultation without a linked appointment is a no-op', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    $record = MedicalRecord::factory()->create([
        'workspace_id' => $ws->id,
        'patient_id' => $patient->id,
        'appointment_id' => null,
    ]);

    Sanctum::actingAs($user);

    $this->postJson("/api/v1/records/{$record->id}/close", [], recordHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.id', $record->id);
});

/*
|--------------------------------------------------------------------------
| Plantillas compuestas — la interacción entre dos ramas paralelas
|--------------------------------------------------------------------------
| The server validates `structured_exam` against a versioned template. The web
| composes the head-to-toe segments into the gyneco and prenatal forms. Without a
| v2 that contains both, a gynecological consultation with anything written in
| "Tórax" is a 422 — the doctor's main workflow, broken by a merge that had no
| textual conflict at all.
*/

it('accepts the body segments in a gynecological consultation', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $response = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control',
            'diagnosis'               => 'Sana',
            'treatment'               => 'Control anual',
            'consultation_type'       => 'gynecological',
            'structured_exam'         => [
                'torax'  => 'simétrico',
                'gestas' => '2',
            ],
        ])
        ->assertCreated();

    $record = App\Models\MedicalRecord::findOrFail($response->json('data.id'));

    expect($record->structured_exam['torax'])->toBe('simétrico')
        ->and($record->structured_exam['gestas'])->toBe('2')
        // Stamped with the composed version, not the old one.
        ->and($record->structured_exam_template_version)->toBe(2);
});

it('accepts the body segments in a prenatal check', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control en 4 semanas',
            'consultation_type'       => 'prenatal',
            'structured_exam'         => [
                'abdomen'        => 'grávido',
                'altura_uterina' => '28',
            ],
        ])
        ->assertCreated();
});

/*
|--------------------------------------------------------------------------
| Prenatal v3 — tamizajes operativos (violencia, sustancias, plan de parto,
| lactancia), añadidos sin tocar v1 ni v2.
|--------------------------------------------------------------------------
*/

it('accepts the yes/no screening fields on a prenatal v3 consultation', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $response = $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control en 4 semanas',
            'consultation_type'       => 'prenatal',
            'structured_exam'         => [
                'tamizaje_violencia_intrafamiliar' => 'no',
                'tamizaje_tabaco_alcohol_drogas'    => 'no',
                'plan_parto_conversado'             => 'si',
                'consejeria_lactancia'              => '',
            ],
        ])
        ->assertCreated();

    $record = App\Models\MedicalRecord::findOrFail($response->json('data.id'));
    $current = app(App\Services\Clinical\ClinicalTemplateRegistry::class)->currentVersion('prenatal');

    expect($record->structured_exam['tamizaje_violencia_intrafamiliar'])->toBe('no')
        ->and($record->structured_exam['plan_parto_conversado'])->toBe('si')
        // Sent as "" ("Sin registrar"), arrives here as null: Laravel's own
        // ConvertEmptyStringsToNull middleware runs before this. Accepted as
        // the same "sin registrar" state, not a forced "no" and not a 422.
        ->and($record->structured_exam['consejeria_lactancia'])->toBeNull()
        ->and($record->structured_exam_template_version)->toBe($current)
        ->and($current)->toBe(3);
});

/**
 * Not specific to the new boolean kind: any field cleared back to "" arrives
 * here as null (ConvertEmptyStringsToNull), and the validator used to treat
 * that as a wrong type regardless of kind. A doctor clearing a text field
 * they had typed into, then saving, hit this on every kind of field.
 */
it('accepts a text field cleared back to empty, not just a fresh empty one', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control en 4 semanas',
            'consultation_type'       => 'prenatal',
            'structured_exam'         => ['altura_uterina' => ''],
        ])
        ->assertCreated();
});

it('rejects a screening value that is not sí, no, or empty', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->postJson('/api/v1/records', [
            'patient_id'              => $patient->id,
            'reason_for_consultation' => 'Control prenatal',
            'diagnosis'               => 'Embarazo normal',
            'treatment'               => 'Control en 4 semanas',
            'consultation_type'       => 'prenatal',
            'structured_exam'         => [
                'tamizaje_violencia_intrafamiliar' => 'tal vez',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['structured_exam.tamizaje_violencia_intrafamiliar']);
});

/** Una consulta cerrada con la plantilla vieja se sigue leyendo y editando. */
it('keeps a v1 gynecological record readable and editable', function () {
    $s = gynecoConsultationScenario();
    Sanctum::actingAs($s['ownerUser']);
    $patient = App\Models\Patient::factory()->create(['workspace_id' => $s['clinic']->id]);

    $record = App\Models\MedicalRecord::factory()->create([
        'workspace_id'                     => $s['clinic']->id,
        'patient_id'                       => $patient->id,
        'created_by'                       => $s['ownerDoctor']->id,
        'consultation_type'                => 'gynecological',
        'structured_exam_template_version' => 1,
        'structured_exam'                  => ['gestas' => '1'],
        'status'                           => 'draft',
    ]);

    $this->withHeader('X-Workspace-Id', (string) $s['clinic']->id)
        ->putJson("/api/v1/records/{$record->id}", [
            'structured_exam' => ['gestas' => '2'],
        ])
        ->assertOk();

    expect($record->fresh()->structured_exam_template_version)->toBe(1)
        ->and($record->fresh()->structured_exam['gestas'])->toBe('2');
});

it('keeps the evolución apart from the enfermedad actual', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);
    Sanctum::actingAs($user);

    $record = $this->postJson('/api/v1/records', [
        'patient_id' => $patient->id,
        'reason_for_consultation' => 'Control',
        'evolution' => 'Mejoró con el tratamiento anterior.',
        'current_illness' => 'Tos de tres días.',
        'closed_with_override' => true,
    ], recordHeaders($ws->id))->assertCreated()->json('data');

    expect($record['evolution'])->toBe('Mejoró con el tratamiento anterior.')
        ->and($record['current_illness'])->toBe('Tos de tres días.');

    $this->putJson("/api/v1/records/{$record['id']}", ['reason_for_consultation' => 'Control', 'evolution' => 'Sin cambios.'], recordHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.evolution', 'Sin cambios.')
        ->assertJsonPath('data.current_illness', 'Tos de tres días.');
});
