<?php

namespace App\Services\Elo;

use Illuminate\Support\Str;

/**
 * Elo listens: the browser's speech-to-text transcript of the visit, sorted
 * into the atención's fields (motivo, enfermedad actual, evolución, examen
 * físico) plus CIE-10 diagnoses.
 *
 * Only text arrives here — the audio never leaves the browser's own speech
 * service — and the web scrubs cédula-like numbers first (ADR-017). Codes go
 * through ResolvesCie10. Nothing is written here: the doctor decides.
 */
class DictationSuggester
{
    use ClinicalPicture;
    use ResolvesCie10;

    private const MAX_DIAGNOSES = 3;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $rules
     * @return array{reason: string, illness: string, evolution: string, exam: string, diagnoses: list<array{code: string, description: string, type: string}>, dropped_codes: int, why: string, rules_applied: list<string>, disclaimer: string}
     */
    public function suggest(array $context, array $rules = []): array
    {
        $prompt = $this->picture($context, $rules)
            ."\n\nTranscripción de la consulta:\n\"\"\"\n".trim((string) $context['transcript'])."\n\"\"\"";

        $answer = $this->gemini->generateJson($this->system(), $prompt, $this->schema(), 35);

        [$diagnoses, $dropped] = $this->resolveDiagnoses((array) ($answer['diagnoses'] ?? []), self::MAX_DIAGNOSES);

        return [
            'reason' => $this->clean($answer['reason'] ?? '', 1000),
            'illness' => $this->clean($answer['illness'] ?? '', 3000),
            'evolution' => $this->clean($answer['evolution'] ?? '', 3000),
            'exam' => $this->clean($answer['exam'] ?? '', 3000),
            'diagnoses' => $diagnoses,
            'dropped_codes' => $dropped,
            'why' => trim((string) ($answer['why'] ?? '')),
            'rules_applied' => $this->appliedRules($answer, $rules),
            'disclaimer' => PrescriptionSuggester::DISCLAIMER,
        ];
    }

    private function clean(mixed $value, int $max): string
    {
        return Str::limit(trim((string) $value), $max, '');
    }

    private function system(): string
    {
        return <<<'PROMPT'
        Eres Elo, el asistente de un médico en Ecuador. Recibes la TRANSCRIPCIÓN AUTOMÁTICA de una
        consulta: puede tener errores de reconocimiento de voz, no tiene puntuación fiable y mezcla
        lo que dice el médico con lo que dice el paciente, sin indicar quién habla. Corrige los
        errores evidentes de reconocimiento (por ejemplo términos médicos mal oídos) y reparte lo
        dicho en los campos de la historia clínica, en español clínico:
        1. "reason": MOTIVO DE CONSULTA, una frase corta (ej. "Dolor de garganta y fiebre").
        2. "illness": ENFERMEDAD ACTUAL en prosa breve (3 a 6 frases), cronológica: inicio,
           tiempo de evolución, síntomas, lo que ha tomado.
        3. "evolution": EVOLUCIÓN, solo si es un control o seguimiento (cómo ha estado desde la
           última visita, respuesta al tratamiento). Vacío si es la primera vez por este cuadro.
        4. "exam": EXAMEN FÍSICO, solo los hallazgos que el médico dijo haber encontrado. Vacío si
           no dijo ninguno. No repitas aquí los signos vitales.
        5. "diagnoses": hasta 3 diagnósticos CIE-10 (código con punto, ej. J03.9, y su
           descripción en español). El más probable primero con "type" primary; los demás
           secondary, o presumptive si son a descartar.
        6. "why": una frase de por qué esos diagnósticos.
        Reglas estrictas:
        - USA SOLO hechos que aparecen en la transcripción. No agregues síntomas, tiempos,
          negativos ni hallazgos que nadie dijo. Si un campo no tiene datos, déjalo vacío.
        - NUNCA escribas nombres de personas, cédulas, teléfonos ni direcciones, aunque se digan.
          Escribe "el paciente" o "la paciente".
        - Ignora saludos, charla y lo que no sea clínico.
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
                'reason' => ['type' => 'STRING'],
                'illness' => ['type' => 'STRING'],
                'evolution' => ['type' => 'STRING'],
                'exam' => ['type' => 'STRING'],
                'diagnoses' => $this->diagnosesSchema(),
                'why' => ['type' => 'STRING'],
                'rules_applied' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['reason', 'illness', 'evolution', 'exam', 'diagnoses', 'why'],
        ];
    }
}
