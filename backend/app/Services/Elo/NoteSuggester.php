<?php

namespace App\Services\Elo;

use Illuminate\Support\Str;

/**
 * Elo's note agent: the doctor's quick notes as a clean "enfermedad actual",
 * plus CIE-10 diagnoses.
 *
 * Codes go through ResolvesCie10, so Elo never invents one. Nothing is
 * written here.
 */
class NoteSuggester
{
    use ClinicalPicture;
    use ResolvesCie10;

    private const MAX_DIAGNOSES = 3;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $rules
     * @return array{illness: string, diagnoses: list<array{code: string, description: string, type: string}>, dropped_codes: int, reason: string, rules_applied: list<string>, disclaimer: string}
     */
    public function suggest(array $context, array $rules = []): array
    {
        $answer = $this->gemini->generateJson($this->system(), $this->picture($context, $rules), $this->schema());

        [$diagnoses, $dropped] = $this->resolveDiagnoses((array) ($answer['diagnoses'] ?? []), self::MAX_DIAGNOSES);

        return [
            'illness' => Str::limit(trim((string) ($answer['illness'] ?? '')), 3000, ''),
            'diagnoses' => $diagnoses,
            'dropped_codes' => $dropped,
            'reason' => trim((string) ($answer['reason'] ?? '')),
            'rules_applied' => $this->appliedRules($answer, $rules),
            'disclaimer' => PrescriptionSuggester::DISCLAIMER,
        ];
    }

    private function system(): string
    {
        return <<<'PROMPT'
        Eres Elo, el asistente de un médico en Ecuador. Recibes lo que el médico escribió rápido
        en la consulta y propones:
        1. "illness": la ENFERMEDAD ACTUAL redactada en limpio, en español clínico, en prosa
           breve (3 a 6 frases), cronológica. USA SOLO los hechos que el médico escribió: no
           agregues síntomas, tiempos, negativos ni hallazgos que no estén. Si hay pocos datos,
           devuelve lo que hay, ordenado. NO incluyas el examen físico ni los signos vitales:
           tienen su propio campo; úsalos solo para elegir los diagnósticos.
        2. "diagnoses": hasta 3 diagnósticos CIE-10 (código con punto, ej. J03.9, y su
           descripción en español). El más probable primero con "type" primary; los demás
           secondary, o presumptive si son a descartar.
        3. "reason": una frase de por qué esos diagnósticos.
        Si te dan "Preferencias del médico", respétalas (estilo de redacción, códigos que
        prefiere). En "rules_applied" copia TEXTUALMENTE las que usaste; vacío si ninguna.
        PROMPT;
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'illness' => ['type' => 'STRING'],
                'diagnoses' => $this->diagnosesSchema(),
                'reason' => ['type' => 'STRING'],
                'rules_applied' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['illness', 'diagnoses', 'reason'],
        ];
    }
}
