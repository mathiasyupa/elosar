<?php

namespace App\Services\Elo;

use App\Models\Medication;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Elo's prescription agent: a draft receta for the diagnosis on screen.
 *
 * What goes to Gemini is the clinical picture only — age, sex, weight, the
 * reason, the diagnoses and the allergies. Never a name, a cédula or any id:
 * the context is built by the caller and nothing here reads a patient.
 *
 * The model proposes; fixed rules then check it. A medication matching a
 * recorded allergy is dropped (and named, so the doctor sees why), and
 * anything the catalog marks as controlled is flagged. The doctor accepts,
 * edits or rejects — nothing is written by this class.
 */
class PrescriptionSuggester
{
    public const DISCLAIMER = 'Sugerencia de apoyo: el médico revisa y decide.';

    private const MAX_ITEMS = 4;

    /**
     * An allergy to the family blocks every member: "penicilina" must also
     * stop amoxicilina. Keys and members are normalised (lower case, no
     * accents) and matched as substrings, so "alergia a penicilinas" works too.
     */
    private const ALLERGY_FAMILIES = [
        'penicilin' => ['penicilin', 'amoxicilin', 'ampicilin', 'oxacilin', 'dicloxacilin', 'piperacilin', 'benzatin'],
        'cefalosporin' => ['cef'],
        'sulfa' => ['sulfa', 'trimetoprim', 'cotrimoxazol'],
        'aine' => ['ibuprofen', 'naproxen', 'diclofenac', 'ketorolac', 'aspirin', 'acido acetilsalicilico', 'metamizol', 'meloxicam'],
        'macrolid' => ['azitromicin', 'claritromicin', 'eritromicin'],
    ];

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ActiveWorkspace $workspace,
    ) {}

    /**
     * @param  array{age?: ?int, sex?: ?string, weight_kg?: ?float, reason?: ?string, illness?: ?string,
     *               diagnoses: list<array{code?: ?string, description?: ?string}>, allergies?: list<string>}  $context
     * @param  list<string>  $rules  The doctor's own preferences (Ajustes → Agentes). Allergies still win.
     * @return array{items: list<array<string, mixed>>, reason: string, removed_for_allergy: list<string>, rules_applied: list<string>, disclaimer: string}
     */
    public function suggest(array $context, array $rules = []): array
    {
        $answer = $this->gemini->generateJson($this->system(), $this->prompt($context, $rules), $this->schema());

        $allergies = $this->blockedTerms($context['allergies'] ?? []);

        $controlled = $this->controlledNames();
        $removed = [];
        $items = [];

        foreach (array_slice((array) ($answer['items'] ?? []), 0, self::MAX_ITEMS) as $raw) {
            if (! is_array($raw) || trim((string) ($raw['generic_name'] ?? '')) === '') {
                continue;
            }

            $item = $this->normalize($raw);
            $haystack = $this->norm($item['generic_name'].' '.$item['medication_name']);

            if ($allergies->contains(fn ($a) => str_contains($haystack, $a))) {
                $removed[] = $item['generic_name'];

                continue;
            }

            $item['is_controlled'] = $controlled->contains(fn ($c) => $c !== '' && str_contains($haystack, $c));
            $items[] = $item;
        }

        return [
            'items' => $items,
            'reason' => trim((string) ($answer['reason'] ?? '')),
            'removed_for_allergy' => $removed,
            // Only rules the doctor actually has: the model may not invent one.
            'rules_applied' => array_values(array_intersect(
                array_map('trim', array_map('strval', (array) ($answer['rules_applied'] ?? []))),
                $rules,
            )),
            'disclaimer' => self::DISCLAIMER,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function normalize(array $raw): array
    {
        $text = fn (string $key, int $max = 255) => Str::limit(trim((string) ($raw[$key] ?? '')), $max, '');
        $int = fn (string $key) => is_numeric($raw[$key] ?? null) ? max(0, (int) $raw[$key]) : null;
        $generic = $text('generic_name', 120);

        return [
            // The brand only when the doctor's rules asked for one; otherwise the generic.
            'medication_name' => $text('brand_name', 120) ?: $generic,
            'generic_name' => $generic,
            'concentration' => $text('concentration', 60),
            'dosage' => $text('dosage', 120),
            'route' => $text('route', 40),
            'frequency' => $text('frequency', 120),
            'duration_days' => $int('duration_days'),
            'quantity' => $int('quantity'),
            'instructions' => $text('instructions', 500),
            'is_controlled' => false,
        ];
    }

    /**
     * First word of every controlled medication visible to this workspace
     * (the global catalog plus its own), normalised.
     *
     * @return Collection<int, string>
     */
    private function controlledNames()
    {
        return Medication::query()
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $this->workspace->id()))
            ->where('is_controlled', true)
            ->pluck('name')
            ->map(fn ($name) => Str::before($this->norm((string) $name), ' '))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Every term an allergy rules out: the allergy itself plus, when it names
     * a family, all of its members.
     *
     * @param  list<string>  $allergies
     * @return IlluminateSupportCollection<int, string>
     */
    private function blockedTerms(array $allergies)
    {
        return collect($allergies)
            ->map(fn ($a) => $this->norm((string) $a))
            ->filter(fn ($a) => mb_strlen($a) >= 3)
            ->flatMap(function (string $allergy) {
                $terms = [$allergy];
                foreach (self::ALLERGY_FAMILIES as $family => $members) {
                    if (str_contains($allergy, $family)) {
                        array_push($terms, ...$members);
                    }
                }

                return $terms;
            })
            ->unique()
            ->values();
    }

    private function norm(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }

    private function system(): string
    {
        return <<<'PROMPT'
        Eres Elo, el asistente de un médico en Ecuador. Propones un BORRADOR de receta para el
        diagnóstico que te dan; el médico lo revisa y decide.

        Reglas:
        - Tratamiento de primera línea según la práctica habitual en Ecuador y las guías del MSP.
        - Medicamentos por su nombre genérico (DCI), en presentaciones que existen en Ecuador.
        - "brand_name": SOLO si una preferencia del médico pide esa marca (ej. «Buprex»); si no, vacío.
        - Ajusta la dosis a la edad y, si lo tienes, al peso. En niños, presentaciones pediátricas.
        - NUNCA propongas un medicamento al que el paciente es alérgico ni de su misma familia.
        - Máximo 4 medicamentos; menos es mejor. Si el diagnóstico no requiere medicación,
          devuelve la lista vacía y dilo en "reason".
        - "quantity" es el total de unidades para todo el tratamiento.
        - "instructions": breve, en español, para el paciente.
        - "reason": una o dos frases, en español, de por qué propones eso.
        - No inventes datos del paciente que no te dieron.
        - Si te dan "Preferencias del médico", respétalas salvo que sean inseguras para este
          paciente (la alergia siempre manda). En "rules_applied" copia TEXTUALMENTE las
          preferencias que influyeron en tu propuesta; vacío si ninguna.
        PROMPT;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<string>  $rules
     */
    private function prompt(array $c, array $rules = []): string
    {
        $sex = ['male' => 'masculino', 'female' => 'femenino'][$c['sex'] ?? ''] ?? 'no indicado';
        $diagnoses = collect($c['diagnoses'] ?? [])
            ->map(fn ($d) => trim(($d['code'] ?? '').' '.($d['description'] ?? '')))
            ->filter()
            ->implode('; ');
        $allergies = collect($c['allergies'] ?? [])->filter()->implode(', ') ?: 'ninguna registrada';

        return implode("\n", array_filter([
            'Edad: '.(isset($c['age']) ? $c['age'].' años' : 'no indicada'),
            'Sexo: '.$sex,
            isset($c['weight_kg']) ? 'Peso: '.$c['weight_kg'].' kg' : null,
            ! empty($c['reason']) ? 'Motivo de consulta: '.$c['reason'] : null,
            ! empty($c['illness']) ? 'Evolución: '.$c['illness'] : null,
            'Diagnóstico(s): '.$diagnoses,
            'Alergias: '.$allergies,
            $rules ? "Preferencias del médico:\n- ".implode("\n- ", $rules) : null,
        ]));
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'items' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'generic_name' => ['type' => 'STRING'],
                            'brand_name' => ['type' => 'STRING'],
                            'concentration' => ['type' => 'STRING'],
                            'dosage' => ['type' => 'STRING'],
                            'route' => ['type' => 'STRING'],
                            'frequency' => ['type' => 'STRING'],
                            'duration_days' => ['type' => 'INTEGER'],
                            'quantity' => ['type' => 'INTEGER'],
                            'instructions' => ['type' => 'STRING'],
                        ],
                        'required' => ['generic_name', 'dosage', 'frequency'],
                    ],
                ],
                'reason' => ['type' => 'STRING'],
                'rules_applied' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['items', 'reason'],
        ];
    }
}
