<?php

use App\Models\EloRule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| Ajustes → Agentes (ADR-017, part 2): each doctor's rules for Elo, the agent
| switch, and the chat that only PROPOSES rules. Gemini is faked.
*/

/** Gemini answers with this JSON object (as text, like the real API). */
function fakeGeminiJson(array $answer): void
{
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
        ]),
    ]);
}

function rxContext(array $over = []): array
{
    return array_merge([
        'age' => 30, 'sex' => 'female', 'reason' => 'Dolor de garganta',
        'diagnoses' => [['code' => 'J03.9', 'description' => 'Amigdalitis aguda']], 'allergies' => [],
    ], $over);
}

it('lists the three agents with the doctor\'s switch, rules and acceptance', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'Prefiero ibuprofeno a diclofenaco'], $h)->assertCreated();
    foreach (['accepted', 'accepted', 'edited', 'rejected'] as $outcome) {
        $this->postJson('/api/v1/elo/events', ['agent' => 'prescription', 'outcome' => $outcome], $h)->assertNoContent();
    }

    $this->getJson('/api/v1/elo/agents', $h)
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.key', 'prescription')
        ->assertJsonPath('data.0.available', true)
        ->assertJsonPath('data.0.enabled', true)
        ->assertJsonPath('data.0.rules_count', 1)
        ->assertJsonPath('data.0.accepted_rate', 75)
        ->assertJsonPath('data.1.available', true);
});

it('saves, edits, switches off and deletes a rule', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->postJson('/api/v1/elo/agents/prescription/rules', ['texts' => ['No tengo cefalexina en farmacia', 'En amigdalitis doy 10 días']], $h)
        ->assertCreated()
        ->assertJsonCount(2, 'data');

    $rule = EloRule::query()->oldest('id')->first();
    $this->patchJson("/api/v1/elo/rules/{$rule->id}", ['text' => 'No tengo cefalexina', 'enabled' => false], $h)
        ->assertOk()
        ->assertJsonPath('data.text', 'No tengo cefalexina')
        ->assertJsonPath('data.enabled', false);

    $this->deleteJson("/api/v1/elo/rules/{$rule->id}", [], $h)->assertNoContent();
    $this->getJson('/api/v1/elo/agents/prescription/rules', $h)->assertJsonCount(1, 'data');
});

it('keeps rules personal: another doctor of the same clinic cannot see or touch them', function () {
    $s = makeClinicScenario();
    $h = ['X-Workspace-Id' => (string) $s['clinic']->id];

    Sanctum::actingAs($s['ownerUser']);
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'Prefiero ibuprofeno'], $h)->assertCreated();
    $rule = EloRule::query()->sole();

    Sanctum::actingAs($s['memUser']);
    $this->getJson('/api/v1/elo/agents/prescription/rules', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->patchJson("/api/v1/elo/rules/{$rule->id}", ['text' => 'Hackeado'], $h)->assertNotFound();
    $this->deleteJson("/api/v1/elo/rules/{$rule->id}", [], $h)->assertNotFound();

    expect($rule->fresh()->text)->toBe('Prefiero ibuprofeno');
});

it('hides a rule from another clinic', function () {
    [, $userA, $wsA] = makeDoctorWithWorkspace();
    [, $userB, $wsB] = makeDoctorWithWorkspace();

    Sanctum::actingAs($userA);
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'Prefiero ibuprofeno'], ['X-Workspace-Id' => (string) $wsA->id])->assertCreated();
    $rule = EloRule::query()->sole();

    Sanctum::actingAs($userB);
    $this->deleteJson("/api/v1/elo/rules/{$rule->id}", [], ['X-Workspace-Id' => (string) $wsB->id])->assertNotFound();
    expect(EloRule::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a rule that carries a cédula, and more than thirty rules', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'A 1710034065 no darle aines'], $h)
        ->assertUnprocessable()
        ->assertJsonPath('errors.text.0', 'Una regla no lleva cédulas ni datos de un paciente.');

    $this->postJson('/api/v1/elo/agents/prescription/rules', ['texts' => array_map(fn ($i) => "Regla número $i", range(1, 10))], $h)->assertCreated();
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['texts' => array_map(fn ($i) => "Otra regla $i", range(1, 10))], $h)->assertCreated();
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['texts' => array_map(fn ($i) => "Más regla $i", range(1, 10))], $h)->assertCreated();
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'La treinta y uno'], $h)->assertUnprocessable();
});

it('rejects an agent outside the catalog', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/elo/agents/finanzas/rules', ['text' => 'algo'], ['X-Workspace-Id' => (string) $ws->id])->assertNotFound();
});

it('the teaching chat proposes rules and saves nothing on its own', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'En amigdalitis doy 10 días'], $h)->assertCreated();

    fakeGeminiJson([
        'reply' => 'Anotado. ¿Lo guardo así?',
        'proposed_rules' => ['Prefiero ibuprofeno a diclofenaco para el dolor', 'En amigdalitis doy 10 días', 'Paciente 1710034065 sin aines'],
    ]);

    $this->postJson('/api/v1/elo/agents/prescription/teach', [
        'message' => 'Casi nunca uso diclofenaco, prefiero ibuprofeno',
        'history' => [['role' => 'elo', 'text' => 'Hola, cuéntame cómo recetas.']],
    ], $h)
        ->assertOk()
        ->assertJsonPath('data.reply', 'Anotado. ¿Lo guardo así?')
        // Duplicates of what it already knows and anything with a cédula are dropped.
        ->assertJsonPath('data.proposed_rules', ['Prefiero ibuprofeno a diclofenaco para el dolor']);

    expect(EloRule::query()->count())->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), 'En amigdalitis doy 10 d'));
});

it('the receta follows the doctor\'s active rules, and the allergy still wins', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];

    $this->postJson('/api/v1/elo/agents/prescription/rules', ['texts' => ['Prefiero ibuprofeno a diclofenaco', 'Regla apagada']], $h)->assertCreated();
    $off = EloRule::query()->where('text', 'Regla apagada')->sole();
    $this->patchJson("/api/v1/elo/rules/{$off->id}", ['enabled' => false], $h)->assertOk();

    fakeGeminiJson([
        'items' => [['generic_name' => 'Ibuprofeno', 'dosage' => '400 mg', 'frequency' => 'cada 8 horas']],
        'reason' => 'Analgesia según tu preferencia.',
        'rules_applied' => ['Prefiero ibuprofeno a diclofenaco', 'Una regla que no existe'],
    ]);

    $this->postJson('/api/v1/elo/prescription', rxContext(), $h)
        ->assertOk()
        ->assertJsonPath('data.items.0.generic_name', 'Ibuprofeno')
        ->assertJsonPath('data.rules_applied', ['Prefiero ibuprofeno a diclofenaco']);

    Http::assertSent(function (Request $r) {
        $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

        return str_contains($body, 'Prefiero ibuprofeno a diclofenaco') && ! str_contains($body, 'Regla apagada');
    });

    // An allergy to AINEs beats the preference for ibuprofeno.
    $this->postJson('/api/v1/elo/prescription', rxContext(['allergies' => ['AINE']]), $h)
        ->assertOk()
        ->assertJsonCount(0, 'data.items')
        ->assertJsonPath('data.removed_for_allergy.0', 'Ibuprofeno');
});

it('writes the brand the doctor prefers, and its allergy check still reads the generic', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    fakeGeminiJson([
        'items' => [['generic_name' => 'Ibuprofeno', 'brand_name' => 'Buprex', 'dosage' => '400 mg', 'frequency' => 'cada 8 horas']],
        'reason' => 'Tu marca preferida.',
    ]);

    $this->postJson('/api/v1/elo/prescription', rxContext(), $h)
        ->assertOk()
        ->assertJsonPath('data.items.0.medication_name', 'Buprex')
        ->assertJsonPath('data.items.0.generic_name', 'Ibuprofeno');

    $this->postJson('/api/v1/elo/prescription', rxContext(['allergies' => ['AINE']]), $h)
        ->assertOk()
        ->assertJsonCount(0, 'data.items');
});

it('a switched-off agent does not propose', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    fakeGeminiJson(['items' => [], 'reason' => '']);

    $this->putJson('/api/v1/elo/agents/prescription', ['enabled' => false], $h)->assertOk();
    $this->postJson('/api/v1/elo/prescription', rxContext(), $h)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Elo de receta está apagado.');
    Http::assertNothingSent();

    $this->getJson('/api/v1/elo/agents', $h)->assertJsonPath('data.0.enabled', false);
});

it('is only for who can use the assistant', function () {
    $s = makeClinicScenario();
    $h = ['X-Workspace-Id' => (string) $s['clinic']->id];

    Sanctum::actingAs($s['nurseUser']);
    $this->getJson('/api/v1/elo/agents', $h)->assertForbidden();
    $this->postJson('/api/v1/elo/agents/prescription/rules', ['text' => 'Prefiero ibuprofeno'], $h)->assertForbidden();
    $this->postJson('/api/v1/elo/agents/prescription/teach', ['message' => 'hola'], $h)->assertForbidden();
});
