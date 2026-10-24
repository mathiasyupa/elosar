<?php

use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** Workspace header helper local to this file (avoids cross-file collisions). */
function assistantHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** Fakes a two-step Gemini conversation: one tool call, then a final text reply. */
function fakeGeminiToolCall(string $toolName, array $args, string $finalText): void
{
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [
                ['functionCall' => ['name' => $toolName, 'args' => $args]],
            ]]]]])
            ->push(['candidates' => [['content' => ['parts' => [
                ['text' => $finalText],
            ]]]]]),
    ]);
}

// ─────────────────────────────────────────────
// Isolation
// ─────────────────────────────────────────────

it('keeps the search_patients_by_condition tool workspace-isolated', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Paciente Propio']);
    MedicalRecord::factory()->create([
        'workspace_id'            => $ws->id,
        'patient_id'              => $patient->id,
        'created_by'              => $doctor->id,
        'reason_for_consultation' => 'dolor de cabeza intenso',
    ]);

    // Another workspace has a patient matching the same term — must never surface.
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id, 'name' => 'Paciente Ajeno']);
    MedicalRecord::factory()->create([
        'workspace_id'            => $foreignWs->id,
        'patient_id'              => $foreignPatient->id,
        'reason_for_consultation' => 'dolor de cabeza fuerte',
    ]);

    fakeGeminiToolCall('search_patients_by_condition', ['term' => 'dolor de cabeza'], 'Encontré 1 paciente con ese síntoma.');

    Sanctum::actingAs($user);

    $reply = $this->postJson('/api/v1/assistant/chat', [
        'message' => '¿Qué pacientes tienen dolor de cabeza?',
    ], assistantHeaders($ws->id))
        ->assertOk()
        ->json('data.reply');

    expect($reply)->toBe('Encontré 1 paciente con ese síntoma.');

    // The tool result sent back to Gemini must contain only the own-workspace
    // patient, never the foreign one.
    Http::assertSent(function ($request) {
        $json = json_encode($request->data());

        return str_contains($json, 'Paciente Propio') && ! str_contains($json, 'Paciente Ajeno');
    });
});

it('does not leak a patient from another workspace when a tool asks for it by id', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $foreignWs = Workspace::factory()->create();
    $foreignPatient = Patient::factory()->create(['workspace_id' => $foreignWs->id, 'name' => 'Paciente Ajeno']);

    fakeGeminiToolCall('get_patient_summary', ['patient_id' => $foreignPatient->id], 'No encontré ese paciente en el consultorio activo.');

    Sanctum::actingAs($user);

    $reply = $this->postJson('/api/v1/assistant/chat', [
        'message' => "Resume la ficha del paciente {$foreignPatient->id}",
    ], assistantHeaders($ws->id))
        ->assertOk()
        ->json('data.reply');

    expect($reply)->toBe('No encontré ese paciente en el consultorio activo.');

    Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()), 'Paciente Ajeno'));

    $this->assertDatabaseMissing('access_logs', ['subject_id' => $foreignPatient->id]);
});

// ─────────────────────────────────────────────
// LODP — access logging
// ─────────────────────────────────────────────

it('logs access when the assistant reads a specific patient summary', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    fakeGeminiToolCall('get_patient_summary', ['patient_id' => $patient->id], 'Resumen listo.');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => "Resume la ficha de {$patient->name}",
    ], assistantHeaders($ws->id))->assertOk();

    $this->assertDatabaseHas('access_logs', [
        'subject_type' => 'patient',
        'subject_id'   => $patient->id,
        'action'       => 'ai_assistant',
        'workspace_id' => $ws->id,
    ]);
});

it('logs access when the assistant lists a patient\'s attachments', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id]);

    fakeGeminiToolCall('get_patient_attachments', ['patient_id' => $patient->id], 'No tiene adjuntos registrados.');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => "¿Dónde están los estudios de {$patient->name}?",
    ], assistantHeaders($ws->id))->assertOk();

    $this->assertDatabaseHas('access_logs', [
        'subject_type' => 'patient',
        'subject_id'   => $patient->id,
        'action'       => 'ai_assistant',
        'workspace_id' => $ws->id,
    ]);
});

// ─────────────────────────────────────────────
// Permissions
// ─────────────────────────────────────────────

it('forbids a nurse (no assistant.use) from using the ai assistant', function () {
    $scenario = makeClinicScenario();

    Sanctum::actingAs($scenario['nurseUser']);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'hola',
    ], assistantHeaders($scenario['clinic']->id))->assertForbidden();
});

it('forbids a receptionist (no assistant.use) from using the ai assistant', function () {
    $scenario = makeClinicScenario();

    Sanctum::actingAs($scenario['recUser']);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'hola',
    ], assistantHeaders($scenario['clinic']->id))->assertForbidden();
});

it('allows a doctor member (has assistant.use) to use the ai assistant', function () {
    $scenario = makeClinicScenario();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hola, ¿en qué te ayudo?']]]]],
        ]),
    ]);

    Sanctum::actingAs($scenario['memUser']);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'hola',
    ], assistantHeaders($scenario['clinic']->id))
        ->assertOk()
        ->assertJsonPath('data.reply', 'Hola, ¿en qué te ayudo?');
});

// ─────────────────────────────────────────────
// Interface navigation
// ─────────────────────────────────────────────

it('returns a navigation suggestion when the model calls open_page', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    fakeGeminiToolCall('open_page', ['page' => 'ajustes_general'], 'Puedes cambiar el tema desde ahí.');

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/assistant/chat', [
        'message' => '¿Dónde cambio el tema?',
    ], assistantHeaders($ws->id))
        ->assertOk()
        ->json('data');

    expect($data['reply'])->toBe('Puedes cambiar el tema desde ahí.')
        ->and($data['navigate'])->toBe(['route' => '/ajustes?pane=general', 'label' => 'Ajustes → General (Apariencia)']);
});

it('returns null navigation when the model never calls open_page', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hola, ¿en qué te ayudo?']]]]],
        ]),
    ]);

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/assistant/chat', [
        'message' => 'hola',
    ], assistantHeaders($ws->id))->assertOk()->json('data');

    expect($data['navigate'])->toBeNull();
});

it('rejects an unknown page key without crashing the loop', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    fakeGeminiToolCall('open_page', ['page' => 'not-a-real-page'], 'No tengo esa sección mapeada.');

    Sanctum::actingAs($user);

    $data = $this->postJson('/api/v1/assistant/chat', [
        'message' => '¿Dónde está X?',
    ], assistantHeaders($ws->id))->assertOk()->json('data');

    expect($data['reply'])->toBe('No tengo esa sección mapeada.')
        ->and($data['navigate'])->toBeNull();
});

// ─────────────────────────────────────────────
// Search hint on empty results
// ─────────────────────────────────────────────

it('includes a retry hint in the tool result when a symptom search finds nothing', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();

    fakeGeminiToolCall('search_patients_by_condition', ['term' => 'dolor de panza'], 'No encontré pacientes con dolor de panza ni abdomen.');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'hay pacientes con dolor de panza',
    ], assistantHeaders($ws->id))->assertOk();

    Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'equivalente clínico estándar'));
});

it('finds a patient via current_illness free text, not just reason/diagnosis', function () {
    [$doctor, $user, $ws] = makeDoctorWithWorkspace();
    $patient = Patient::factory()->create(['workspace_id' => $ws->id, 'name' => 'Con Enfermedad Actual']);
    MedicalRecord::factory()->create([
        'workspace_id'    => $ws->id,
        'patient_id'      => $patient->id,
        'created_by'      => $doctor->id,
        'current_illness' => 'Paciente refiere dolor abdominal difuso de 3 días de evolución.',
    ]);

    fakeGeminiToolCall('search_patients_by_condition', ['term' => 'dolor abdominal'], 'Encontré 1 paciente.');

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [
        'message' => 'quien tiene dolor abdominal',
    ], assistantHeaders($ws->id))->assertOk();

    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), 'Con Enfermedad Actual'));
});

// ─────────────────────────────────────────────
// Validation
// ─────────────────────────────────────────────

it('requires a message', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/assistant/chat', [], assistantHeaders($ws->id))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['message']);
});
