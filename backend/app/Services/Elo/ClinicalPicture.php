<?php

namespace App\Services\Elo;

use Illuminate\Support\Str;

/**
 * The clinical picture as Elo's agents write it into a prompt. Only what the
 * caller passed — age, sex, weight, the visit's text, diagnoses, allergies —
 * never a name, a cédula or an id (ADR-017).
 */
trait ClinicalPicture
{
    /**
     * @param  array<string, mixed>  $c
     * @param  list<string>  $rules
     */
    private function picture(array $c, array $rules = []): string
    {
        $sex = ['male' => 'masculino', 'female' => 'femenino'][$c['sex'] ?? ''] ?? 'no indicado';
        $diagnoses = collect($c['diagnoses'] ?? [])
            ->map(fn ($d) => trim(($d['code'] ?? '').' '.($d['description'] ?? '')))
            ->filter()
            ->implode('; ');

        return implode("\n", array_filter([
            'Edad: '.(isset($c['age']) ? $c['age'].' años' : 'no indicada'),
            'Sexo: '.$sex,
            isset($c['weight_kg']) ? 'Peso: '.$c['weight_kg'].' kg' : null,
            ! empty($c['vitals_summary']) ? 'Signos vitales: '.$c['vitals_summary'] : null,
            ! empty($c['reason']) ? 'Motivo de consulta: '.$c['reason'] : null,
            ! empty($c['illness']) ? 'Enfermedad actual (tal como la escribió el médico): '.$c['illness'] : null,
            ! empty($c['exam']) ? 'Examen físico: '.$c['exam'] : null,
            $diagnoses !== '' ? 'Diagnóstico(s): '.$diagnoses : null,
            'Alergias: '.(collect($c['allergies'] ?? [])->filter()->implode(', ') ?: 'ninguna registrada'),
            $rules ? "Preferencias del médico:\n- ".implode("\n- ", $rules) : null,
        ]));
    }

    /**
     * The rules the model says it used, kept only if the doctor really has them.
     *
     * @param  array<string, mixed>  $answer
     * @param  list<string>  $rules
     * @return list<string>
     */
    private function appliedRules(array $answer, array $rules): array
    {
        return array_values(array_intersect(
            array_map('trim', array_map('strval', (array) ($answer['rules_applied'] ?? []))),
            $rules,
        ));
    }

    private function norm(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}
