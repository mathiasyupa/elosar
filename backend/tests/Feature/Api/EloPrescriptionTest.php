<?php

use App\Models\EloSuggestionEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| Elo — the receta suggestion inside the atención (ADR-017).
| Gemini is faked: these tests pin what Elo sends, the fixed allergy rules
| applied after the model, permissions and the audit of each decision.
*/

function eloHeaders(int $workspaceId): array
{
    return ['X-Workspace-Id' => (string) $workspaceId];
}

/** Gemini answers with these items (as JSON text, like the real API). */
function fakeGemini(array $items, string $reason = 'Primera línea para el diagnóstico.'): void
{
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items, 'reason' => $reason])]]]]],
        ]),
    ]);
}

function eloContext(array $over = []): array
{
    return array_merge([
        'age' => 30,
        'sex' => 'female',
        'weight_kg' => 62,
        'reason' => 'Dolor de garganta y fiebre',
        'diagnoses' => [['code' => 'J03.9', 'description' => 'Amigdalitis aguda']],
        'allergies' => [],
    ], $over);
}

it('proposes a receta as data the atención can use', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    fakeGemini([
        ['generic_name' => 'Amoxicilina', 'concentration' => '500 mg', 'dosage' => '1 cápsula', 'route' => 'Oral',
            'frequency' => 'cada 8 horas', 'duration_days' => 7, 'quantity' => 21, 'instructions' => 'Tras las comidas.'],
    ]);

    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($ws->id))
        ->assertOk()
        ->assertJsonPath('data.items.0.generic_name', 'Amoxicilina')
        ->assertJsonPath('data.items.0.medication_name', 'Amoxicilina')
        ->assertJsonPath('data.items.0.quantity', 21)
        ->assertJsonPath('data.reason', 'Primera línea para el diagnóstico.')
        ->assertJsonPath('data.disclaimer', 'Sugerencia de apoyo: el médico revisa y decide.');
});

it('never sends a name, a cédula or an id to the AI provider', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    fakeGemini([]);

    // Even if the web sent them by mistake, they are not part of the request.
    $this->postJson('/api/v1/elo/prescription', eloContext([
        'name' => 'María Pérez', 'id_number' => '1710034065', 'patient_id' => 99,
    ]), eloHeaders($ws->id))->assertOk();

    Http::assertSent(function (Request $request) {
        $body = json_encode($request->data());

        return str_contains($body, 'Amigdalitis')
            && ! str_contains($body, 'María Pérez')
            && ! str_contains($body, '1710034065');
    });
});

it('drops a medication the patient is allergic to, including its family', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    fakeGemini([
        ['generic_name' => 'Amoxicilina', 'dosage' => '500 mg', 'frequency' => 'cada 8 horas'],
        ['generic_name' => 'Paracetamol', 'dosage' => '500 mg', 'frequency' => 'cada 8 horas'],
    ]);

    $this->postJson('/api/v1/elo/prescription', eloContext(['allergies' => ['Penicilina']]), eloHeaders($ws->id))
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.generic_name', 'Paracetamol')
        ->assertJsonPath('data.removed_for_allergy.0', 'Amoxicilina');
});

it('asks for a diagnosis before proposing anything', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    fakeGemini([]);

    $this->postJson('/api/v1/elo/prescription', eloContext(['diagnoses' => []]), eloHeaders($ws->id))
        ->assertUnprocessable()
        ->assertJsonPath('errors.diagnoses.0', 'Pon un diagnóstico para que Elo proponga una receta.');

    Http::assertNothingSent();
});

it('says Elo is unavailable, without breaking, when Gemini is not configured or fails', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    config(['services.gemini.key' => null]);
    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($ws->id))->assertStatus(503);

    config(['services.gemini.key' => 'test-key']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('boom', 500)]);
    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($ws->id))
        ->assertStatus(503)
        ->assertJsonPath('message', 'Elo no está disponible ahora. Puedes seguir sin él.');
});

it('is only for who can write records and use the assistant', function () {
    $s = makeClinicScenario();
    fakeGemini([]);

    Sanctum::actingAs($s['nurseUser']);
    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($s['clinic']->id))->assertForbidden();

    Sanctum::actingAs($s['recUser']);
    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($s['clinic']->id))->assertForbidden();

    Sanctum::actingAs($s['memUser']);
    $this->postJson('/api/v1/elo/prescription', eloContext(), eloHeaders($s['clinic']->id))->assertOk();
});

it('records what the doctor decided, without clinical content', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/elo/events', ['agent' => 'prescription', 'outcome' => 'accepted', 'items_count' => 2], eloHeaders($ws->id))
        ->assertNoContent();
    $this->postJson('/api/v1/elo/events', ['agent' => 'prescription', 'outcome' => 'aprobado'], eloHeaders($ws->id))
        ->assertUnprocessable();

    $event = EloSuggestionEvent::query()->sole();
    expect($event->workspace_id)->toBe($ws->id)
        ->and($event->user_id)->toBe($user->id)
        ->and($event->outcome)->toBe('accepted')
        ->and($event->items_count)->toBe(2);
});
