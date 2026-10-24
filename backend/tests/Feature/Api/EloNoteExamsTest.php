<?php

use App\Models\Cie10Code;
use App\Models\EloRule;
use App\Models\Study;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| Elo part 3 (ADR-017): the note + CIE-10 agent and the exams agent.
| Gemini is faked. What matters: Elo never invents a code or a study — both
| are checked against the catalogs — and nothing identifying travels.
*/

function geminiAnswers(array $answer): void
{
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
        ]),
    ]);
}

function eloVisit(array $over = []): array
{
    return array_merge([
        'age' => 34, 'sex' => 'female',
        'reason' => 'dolor garganta 3 dias fiebre',
        'illness' => 'odinofagia, fiebre 38.5 desde el lunes, no tos',
        'exam' => 'amigdalas hipertroficas con exudado, adenopatias cervicales',
        'allergies' => [],
    ], $over);
}

beforeEach(function () {
    Cie10Code::query()->create(['code' => 'J03.9', 'description' => 'Amigdalitis aguda, no especificada', 'category' => 'J00-J99']);
    Cie10Code::query()->create(['code' => 'J02.9', 'description' => 'Faringitis aguda, no especificada', 'category' => 'J00-J99']);
});

it('tidies the note and proposes CIE-10 only from the catalog', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiAnswers([
        'illness' => 'Paciente con odinofagia y fiebre de 38.5 °C desde hace 3 días, sin tos.',
        'diagnoses' => [
            ['code' => 'J039', 'description' => 'Amigdalitis', 'type' => 'primary'],          // no dot: rescued
            ['code' => 'X99.9', 'description' => 'Faringitis aguda', 'type' => 'secondary'],  // bad code, rescued by description
            ['code' => 'Z00.0', 'description' => 'Algo inventado', 'type' => 'presumptive'],  // dropped
        ],
        'reason' => 'Fiebre con exudado amigdalino.',
    ]);

    $this->postJson('/api/v1/elo/note', eloVisit(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.illness', 'Paciente con odinofagia y fiebre de 38.5 °C desde hace 3 días, sin tos.')
        ->assertJsonCount(2, 'data.diagnoses')
        ->assertJsonPath('data.diagnoses.0.code', 'J03.9')
        // The catalog's words, never the model's.
        ->assertJsonPath('data.diagnoses.0.description', 'Amigdalitis aguda, no especificada')
        ->assertJsonPath('data.diagnoses.0.type', 'primary')
        ->assertJsonPath('data.diagnoses.1.code', 'J02.9')
        ->assertJsonPath('data.dropped_codes', 1);
});

it('needs something written before tidying the note', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiAnswers([]);

    $this->postJson('/api/v1/elo/note', eloVisit(['reason' => null, 'illness' => null, 'exam' => null]), ['X-Workspace-Id' => (string) $ws->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.reason.0', 'Escribe algo de la consulta para que Elo lo ordene.');
    Http::assertNothingSent();
});

it('never sends a name or a cédula, from either agent', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiAnswers(['illness' => '', 'diagnoses' => [], 'reason' => '', 'items' => [], 'clinical_info' => '']);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    $leak = ['name' => 'María Pérez', 'id_number' => '1710034065', 'patient_id' => 99];

    $this->postJson('/api/v1/elo/note', eloVisit($leak), $h)->assertOk();
    $this->postJson('/api/v1/elo/exams', eloVisit([...$leak, 'diagnoses' => [['code' => 'J03.9', 'description' => 'Amigdalitis']]]), $h)->assertOk();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data()), 'María Pérez')
        && ! str_contains(json_encode($r->data()), '1710034065'));
});

it('proposes exams matched to this clinic\'s catalog, never to another clinic\'s', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    [, , $other] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);

    $bh = Study::query()->create(['type' => 'laboratory', 'name' => 'Biometría hemática completa', 'category' => 'Hematología', 'is_active' => true]);
    $mine = Study::query()->create(['workspace_id' => $ws->id, 'type' => 'laboratory', 'name' => 'Hisopado faríngeo', 'is_active' => true]);
    Study::query()->create(['workspace_id' => $other->id, 'type' => 'laboratory', 'name' => 'PCR ultrasensible', 'is_active' => true]);
    Study::query()->create(['type' => 'imaging', 'name' => 'Radiografía de tórax', 'is_active' => true]);

    geminiAnswers([
        'items' => [
            ['name' => 'biometria hematica completa', 'type' => 'laboratory'],
            ['name' => 'Hisopado faríngeo', 'type' => 'laboratory'],
            ['name' => 'PCR ultrasensible', 'type' => 'laboratory'],
            ['name' => 'Radiografía de tórax', 'type' => 'imaging'],
        ],
        'clinical_info' => 'Amigdalitis aguda febril',
        'reason' => 'Descartar origen bacteriano.',
    ]);

    $this->postJson('/api/v1/elo/exams', eloVisit(['diagnoses' => [['code' => 'J03.9', 'description' => 'Amigdalitis']]]), ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.type', 'laboratory')
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.items.0.study_id', $bh->id)
        ->assertJsonPath('data.items.0.name', 'Biometría hemática completa')
        ->assertJsonPath('data.items.1.study_id', $mine->id)
        // Another clinic's study is not this clinic's catalog.
        ->assertJsonPath('data.items.2.study_id', null)
        ->assertJsonPath('data.items.2.in_catalog', false)
        ->assertJsonPath('data.clinical_info', 'Amigdalitis aguda febril')
        ->assertJsonPath('data.reason', 'Descartar origen bacteriano. Además conviene pedir aparte: Radiografía de tórax.');

    Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Hisopado faríngeo')
        && ! str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'PCR ultrasensible'));
});

it('uses each agent\'s own rules, and a switched-off agent does not propose', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    EloRule::query()->create(['user_id' => $user->id, 'agent' => 'exams', 'text' => 'En amigdalitis pido hisopado']);
    EloRule::query()->create(['user_id' => $user->id, 'agent' => 'prescription', 'text' => 'Prefiero ibuprofeno']);
    geminiAnswers(['items' => [], 'clinical_info' => '', 'reason' => '', 'rules_applied' => ['En amigdalitis pido hisopado']]);

    $this->postJson('/api/v1/elo/exams', eloVisit(['diagnoses' => [['code' => 'J03.9']]]), $h)
        ->assertOk()
        ->assertJsonPath('data.rules_applied', ['En amigdalitis pido hisopado']);
    Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'En amigdalitis pido hisopado')
        && ! str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'Prefiero ibuprofeno'));

    $this->putJson('/api/v1/elo/agents/note', ['enabled' => false], $h)->assertOk();
    $this->postJson('/api/v1/elo/note', eloVisit(), $h)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Elo de nota y CIE-10 está apagado.');
});

it('is only for who writes records and uses the assistant', function () {
    $s = makeClinicScenario();
    $h = ['X-Workspace-Id' => (string) $s['clinic']->id];
    geminiAnswers([]);

    Sanctum::actingAs($s['nurseUser']);
    $this->postJson('/api/v1/elo/note', eloVisit(), $h)->assertForbidden();
    $this->postJson('/api/v1/elo/exams', eloVisit(['diagnoses' => [['code' => 'J03.9']]]), $h)->assertForbidden();
});

it('rides out one overloaded answer from Gemini', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::sequence()
            ->push('overloaded', 503)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode(['illness' => 'Ordenada.', 'diagnoses' => [], 'reason' => ''])]]]]]]),
    ]);

    $this->postJson('/api/v1/elo/note', eloVisit(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.illness', 'Ordenada.');
});

it('says so plainly when Gemini is over its quota', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

    $this->postJson('/api/v1/elo/note', eloVisit(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Elo llegó a su límite de uso por ahora. Puedes seguir sin él.');
});
