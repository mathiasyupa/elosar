<?php

namespace App\Services\Elo;

use Illuminate\Support\Str;

/**
 * The "Enséñale a Elo" chat. The doctor talks; Elo answers and PROPOSES
 * short rules. Nothing is saved here — the doctor keeps the ones they want
 * (ADR-017: no hidden memory). The chat history lives only in the browser.
 */
class RuleTeacher
{
    private const MAX_PROPOSALS = 3;

    /** Per agent: an example rule and what its rules may be about. */
    private const SCOPE = [
        'prescription' => ['Prefiero ibuprofeno a diclofenaco para el dolor', 'medicamentos preferidos o no disponibles, marcas, duraciones, presentaciones, dosis habituales'],
        'note' => ['Redacta la enfermedad actual en tercera persona', 'cómo redactar la nota (persona, extensión, orden) y códigos CIE-10 que prefiere usar'],
        'dictation' => ['Escribe el examen físico por aparatos, en frases cortas', 'cómo repartir y redactar lo que se dijo en la consulta (persona, extensión, orden) y códigos CIE-10 que prefiere usar'],
        'exams' => ['En control prenatal pido biometría, EMO y glucosa', 'exámenes que suele pedir según el caso o que no pide, y laboratorios disponibles'],
    ];

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  list<string>  $currentRules
     * @return array{reply: string, proposed_rules: list<string>}
     */
    public function teach(string $agent, string $message, array $history, array $currentRules): array
    {
        $answer = $this->gemini->generateJson(
            $this->system($agent, $currentRules),
            $this->prompt($history, $message),
            $this->schema(),
        );

        $known = collect($currentRules)->map(fn ($r) => $this->norm($r));

        $proposals = collect((array) ($answer['proposed_rules'] ?? []))
            ->map(fn ($r) => Str::limit(trim((string) $r), 300, ''))
            ->filter(fn ($r) => $r !== '' && ! preg_match('/\d{10}/', $r))
            ->reject(fn ($r) => $known->contains($this->norm($r)))
            ->unique()
            ->take(self::MAX_PROPOSALS)
            ->values()
            ->all();

        return [
            'reply' => Str::limit(trim((string) ($answer['reply'] ?? '')), 1000, '') ?: 'Entendido.',
            'proposed_rules' => $proposals,
        ];
    }

    private function norm(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    /** @param list<string> $currentRules */
    private function system(string $agent, array $currentRules): string
    {
        $what = EloAgents::ALL[$agent]['name'] ?? $agent;
        $rules = $currentRules ? '- '.implode("\n- ", $currentRules) : '(ninguna todavía)';
        [$example, $scope] = self::SCOPE[$agent] ?? self::SCOPE['prescription'];

        return <<<PROMPT
        Eres Elo, el asistente de un médico en Ecuador. Ahora el médico te está enseñando sus
        preferencias para tu función «{$what}». Conversa breve y cordial, en español.

        Tu trabajo es convertir lo que dice en REGLAS cortas que guardará si quiere:
        - Cada regla: una sola frase, máximo 20 palabras, en primera persona del médico
          (ej.: «{$example}»).
        - Solo preferencias de práctica clínica: {$scope}.
        - NUNCA reglas sobre un paciente concreto, ni con nombres o números de cédula.
        - Si algo que pide es inseguro, dilo en "reply" y no lo propongas como regla.
        - Si no hay nada que convertir en regla (saludo, pregunta), "proposed_rules" vacío.
        - No repitas reglas que ya tiene.

        Reglas que ya tiene:
        {$rules}
        PROMPT;
    }

    /** @param list<array{role: string, text: string}> $history */
    private function prompt(array $history, string $message): string
    {
        $lines = collect($history)
            ->map(fn ($h) => (($h['role'] ?? '') === 'elo' ? 'Elo: ' : 'Médico: ').trim((string) ($h['text'] ?? '')))
            ->push('Médico: '.trim($message));

        return "Conversación:\n".$lines->implode("\n");
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'reply' => ['type' => 'STRING'],
                'proposed_rules' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['reply', 'proposed_rules'],
        ];
    }
}
