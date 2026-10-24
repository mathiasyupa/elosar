<?php

use App\Models\Cie10Code;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
| "Elo escucha" (ADR-017 addendum): a dictated visit sorted into fields.
| Gemini is faked. What matters: each field lands where it belongs, codes come
| from the catalog, and it is its own agent — off until the doctor turns it on.
*/

function geminiDictates(array $answer): void
{
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
        ]),
    ]);
}

function dictatedVisit(array $over = []): array
{
    return array_merge([
        'age' => 34, 'sex' => 'female', 'allergies' => [],
        'transcript' => 'buenos dias doctor vengo porque me duele la garganta hace tres dias y tengo fiebre de 38 y medio no tengo tos '
            .'a ver abra la boca amigdalas hipertroficas con exudado y ganglios cervicales palpables',
    ], $over);
}

/** Ajustes → Agentes → «Escuchar la consulta»: on. */
function turnOnListening($test, array $h): void
{
    $test->putJson('/api/v1/elo/agents/dictation', ['enabled' => true], $h)->assertOk();
}

beforeEach(function () {
    Cie10Code::query()->create(['code' => 'J03.9', 'description' => 'Amigdalitis aguda, no especificada', 'category' => 'J00-J99']);
});

it('sorts the dictated visit into fields, with CIE-10 only from the catalog', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiDictates([
        'reason' => 'Dolor de garganta y fiebre',
        'illness' => 'Paciente con odinofagia de 3 días y fiebre de 38.5 °C, sin tos.',
        'evolution' => '',
        'exam' => 'Amígdalas hipertróficas con exudado, adenopatías cervicales palpables.',
        'diagnoses' => [
            ['code' => 'J039', 'description' => 'Amigdalitis', 'type' => 'primary'],
            ['code' => 'Z00.0', 'description' => 'Algo inventado', 'type' => 'secondary'],
        ],
        'why' => 'Fiebre con exudado amigdalino.',
    ]);

    turnOnListening($this, ['X-Workspace-Id' => (string) $ws->id]);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk()
        ->assertJsonPath('data.reason', 'Dolor de garganta y fiebre')
        ->assertJsonPath('data.illness', 'Paciente con odinofagia de 3 días y fiebre de 38.5 °C, sin tos.')
        ->assertJsonPath('data.evolution', '')
        ->assertJsonPath('data.exam', 'Amígdalas hipertróficas con exudado, adenopatías cervicales palpables.')
        ->assertJsonCount(1, 'data.diagnoses')
        ->assertJsonPath('data.diagnoses.0.code', 'J03.9')
        ->assertJsonPath('data.diagnoses.0.description', 'Amigdalitis aguda, no especificada')
        ->assertJsonPath('data.dropped_codes', 1)
        ->assertJsonPath('data.why', 'Fiebre con exudado amigdalino.');

    Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'me duele la garganta hace tres dias'));
});

it('needs a transcript', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiDictates([]);

    turnOnListening($this, ['X-Workspace-Id' => (string) $ws->id]);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(['transcript' => '']), ['X-Workspace-Id' => (string) $ws->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.transcript.0', 'Elo no escuchó nada. Intenta de nuevo.');
    Http::assertNothingSent();
});

it('never sends a name, a cédula or a patient id that was not in the transcript', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    geminiDictates(['reason' => '', 'illness' => '', 'evolution' => '', 'exam' => '', 'diagnoses' => [], 'why' => '']);

    turnOnListening($this, ['X-Workspace-Id' => (string) $ws->id]);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(['name' => 'María Pérez', 'id_number' => '1710034065', 'patient_id' => 99]), ['X-Workspace-Id' => (string) $ws->id])
        ->assertOk();

    Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data()), 'María Pérez')
        && ! str_contains(json_encode($r->data()), '1710034065'));
});

it('is off until the doctor turns it on in Ajustes, and the note switch does not touch it', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    $h = ['X-Workspace-Id' => (string) $ws->id];
    geminiDictates(['reason' => 'Dolor de garganta', 'illness' => '', 'evolution' => '', 'exam' => '', 'diagnoses' => [], 'why' => '']);

    $this->getJson('/api/v1/elo/agents', $h)
        ->assertOk()
        ->assertJsonPath('data.3.key', 'dictation')
        ->assertJsonPath('data.3.enabled', false)
        ->assertJsonMissingPath('data.3.default_enabled')
        // The others stay on by default.
        ->assertJsonPath('data.1.enabled', true);

    $this->postJson('/api/v1/elo/dictation', dictatedVisit(), $h)
        ->assertStatus(409)
        ->assertJsonPath('message', 'Elo de escuchar la consulta está apagado.');
    Http::assertNothingSent();

    turnOnListening($this, $h);
    $this->putJson('/api/v1/elo/agents/note', ['enabled' => false], $h)->assertOk();
    $this->getJson('/api/v1/elo/agents', $h)->assertJsonPath('data.3.enabled', true);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(), $h)
        ->assertOk()
        ->assertJsonPath('data.reason', 'Dolor de garganta');
});

it('is only for who writes records and uses the assistant', function () {
    $s = makeClinicScenario();
    geminiDictates([]);

    Sanctum::actingAs($s['nurseUser']);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(), ['X-Workspace-Id' => (string) $s['clinic']->id])->assertForbidden();
});

it('answers quietly when Gemini is down', function () {
    [, $user, $ws] = makeDoctorWithWorkspace();
    Sanctum::actingAs($user);
    config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('down', 500)]);

    turnOnListening($this, ['X-Workspace-Id' => (string) $ws->id]);
    $this->postJson('/api/v1/elo/dictation', dictatedVisit(), ['X-Workspace-Id' => (string) $ws->id])
        ->assertStatus(503)
        ->assertJsonPath('message', 'Elo no está disponible ahora. Puedes seguir sin él.');
});
