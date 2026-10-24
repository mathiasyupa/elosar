<?php

namespace App\Services\Elo;

use App\Models\Study;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Elo's exams agent: a laboratory or imaging order for the diagnosis on screen.
 *
 * The model is shown the studies this clinic can order (global catalog plus
 * its own) and asked to pick from them. Every item is then matched back to
 * that catalog to carry its `study_id`; one that does not match keeps its
 * name and is flagged `in_catalog: false`. The order itself is created by
 * the doctor — nothing is written here.
 */
class ExamSuggester
{
    use ClinicalPicture;

    private const MAX_ITEMS = 8;

    public function __construct(
        private readonly GeminiClient $gemini,
        private readonly ActiveWorkspace $workspace,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $rules
     * @return array{type: string, items: list<array{name: string, study_id: ?int, category: ?string, in_catalog: bool}>, clinical_info: string, reason: string, rules_applied: list<string>, disclaimer: string}
     */
    public function suggest(array $context, array $rules = []): array
    {
        $catalog = $this->catalog();
        $prompt = $this->picture($context, $rules)."\n\n".$this->catalogBlock($catalog);
        $answer = $this->gemini->generateJson($this->system(), $prompt, $this->schema());

        $items = collect((array) ($answer['items'] ?? []))
            ->filter(fn ($raw) => is_array($raw) && trim((string) ($raw['name'] ?? '')) !== '')
            ->map(fn (array $raw) => $this->match($raw, $catalog))
            ->unique(fn ($i) => $this->norm($i['name']))
            ->values();

        // One order is one type: keep the one most items belong to.
        $type = $items->countBy('type')->sortDesc()->keys()->first()
            ?? (in_array($answer['type'] ?? null, [Study::TYPE_LABORATORY, Study::TYPE_IMAGING], true) ? $answer['type'] : Study::TYPE_LABORATORY);
        $left = $items->where('type', '!=', $type)->pluck('name');
        $reason = trim((string) ($answer['reason'] ?? ''));

        if ($left->isNotEmpty()) {
            $reason = trim($reason.' Además conviene pedir aparte: '.$left->implode(', ').'.');
        }

        return [
            'type' => $type,
            'items' => $items->where('type', $type)->take(self::MAX_ITEMS)
                ->map(fn ($i) => collect($i)->except('type')->all())->values()->all(),
            'clinical_info' => Str::limit(trim((string) ($answer['clinical_info'] ?? '')), 255, ''),
            'reason' => $reason,
            'rules_applied' => $this->appliedRules($answer, $rules),
            'disclaimer' => PrescriptionSuggester::DISCLAIMER,
        ];
    }

    /** @return Collection<int, Study> */
    private function catalog(): Collection
    {
        $workspaceId = $this->workspace->id();

        return Study::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('workspace_id')->when($workspaceId, fn ($q) => $q->orWhere('workspace_id', $workspaceId)))
            ->orderBy('type')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'type', 'category', 'name']);
    }

    /** @param Collection<int, Study> $catalog */
    private function catalogBlock(Collection $catalog): string
    {
        return $catalog->groupBy('type')
            ->map(fn ($studies, $type) => (Study::TYPES[$type] ?? $type).' disponibles: '.$studies->pluck('name')->implode('; '))
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, Study>  $catalog
     * @return array{name: string, study_id: ?int, category: ?string, in_catalog: bool, type: string}
     */
    private function match(array $raw, Collection $catalog): array
    {
        $name = Str::limit(trim((string) $raw['name']), 150, '');
        $wanted = $this->norm($name);

        $study = $catalog->first(fn (Study $s) => $this->norm($s->name) === $wanted)
            ?? $catalog->first(fn (Study $s) => mb_strlen($wanted) >= 4 && str_starts_with($this->norm($s->name), $wanted));

        if ($study) {
            return ['name' => $study->name, 'study_id' => $study->id, 'category' => $study->category, 'in_catalog' => true, 'type' => $study->type];
        }

        $type = in_array($raw['type'] ?? null, [Study::TYPE_LABORATORY, Study::TYPE_IMAGING], true) ? $raw['type'] : Study::TYPE_LABORATORY;

        return ['name' => $name, 'study_id' => null, 'category' => null, 'in_catalog' => false, 'type' => $type];
    }

    private function system(): string
    {
        return <<<'PROMPT'
        Eres Elo, el asistente de un médico en Ecuador. Propones los EXÁMENES para el diagnóstico
        que te dan; el médico revisa y genera la orden.
        - Elige SOLO de las listas de "disponibles", copiando el nombre TEXTUALMENTE. Si algo
          imprescindible no está en la lista, puedes nombrarlo igual.
        - Lo justo para el caso, según la práctica habitual en Ecuador: máximo 8, menos es mejor.
          Si el diagnóstico no requiere exámenes, lista vacía y dilo en "reason".
        - "type" de cada ítem: laboratory o imaging.
        - "clinical_info": una línea con la indicación clínica para la orden.
        - "reason": una o dos frases de por qué esos exámenes.
        Si te dan "Preferencias del médico", respétalas. En "rules_applied" copia TEXTUALMENTE
        las que usaste; vacío si ninguna.
        PROMPT;
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
                            'name' => ['type' => 'STRING'],
                            'type' => ['type' => 'STRING', 'enum' => [Study::TYPE_LABORATORY, Study::TYPE_IMAGING]],
                        ],
                        'required' => ['name', 'type'],
                    ],
                ],
                'clinical_info' => ['type' => 'STRING'],
                'reason' => ['type' => 'STRING'],
                'rules_applied' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            ],
            'required' => ['items', 'clinical_info', 'reason'],
        ];
    }
}
