<?php

namespace App\Services\Elo;

use App\Models\Cie10Code;
use Illuminate\Support\Str;

/**
 * Elo never invents a code. Every code the model proposes is looked up in
 * `cie10_codes` and returned with the catalog's description; one that does
 * not exist is rescued by description or dropped.
 */
trait ResolvesCie10
{
    /**
     * @param  array<mixed>  $proposed  The model's raw diagnoses.
     * @return array{0: list<array{code: string, description: string, type: string}>, 1: int}  Resolved diagnoses and how many were dropped.
     */
    private function resolveDiagnoses(array $proposed, int $max = 3): array
    {
        $types = ['primary', 'secondary', 'presumptive'];
        $diagnoses = [];
        $dropped = 0;

        foreach (array_slice($proposed, 0, $max) as $raw) {
            $found = is_array($raw) ? $this->resolveCode((string) ($raw['code'] ?? ''), (string) ($raw['description'] ?? '')) : null;

            if (! $found) {
                $dropped++;

                continue;
            }

            if (collect($diagnoses)->contains('code', $found->code)) {
                continue;
            }

            $type = (string) ($raw['type'] ?? '');
            $diagnoses[] = [
                'code' => $found->code,
                'description' => $found->description,
                'type' => in_array($type, $types, true) ? $type : ($diagnoses ? 'secondary' : 'primary'),
            ];
        }

        return [$diagnoses, $dropped];
    }

    /** The catalog entry for a proposed code, or the closest by description. */
    private function resolveCode(string $code, string $description): ?Cie10Code
    {
        $code = Str::upper(trim($code));

        if ($code !== '') {
            // "J039" is "J03.9" written without the dot.
            $dotted = preg_replace('/^([A-Z]\d{2})(\d+)$/', '$1.$2', $code);
            $exact = Cie10Code::query()->whereIn('code', array_unique([$code, $dotted]))->first();

            if ($exact) {
                return $exact;
            }
        }

        $description = trim($description);

        if (mb_strlen($description) < 4) {
            return null;
        }

        return Cie10Code::query()->where('description', 'ILIKE', $description)->first()
            ?? Cie10Code::query()->where('description', 'ILIKE', $description.'%')->orderBy('code')->first();
    }

    /** @return array<string, mixed> */
    private function diagnosesSchema(): array
    {
        return [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'code' => ['type' => 'STRING'],
                    'description' => ['type' => 'STRING'],
                    'type' => ['type' => 'STRING', 'enum' => ['primary', 'secondary', 'presumptive']],
                ],
                'required' => ['code', 'description'],
            ],
        ];
    }
}
