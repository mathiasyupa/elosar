<?php

namespace App\Services\Clinical;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Immutable clinical exploration templates owned by the product.
 *
 * Once a version is used, retain its definition here. A workspace never owns
 * these definitions: a clinical template is not a free-form form builder.
 */
class ClinicalTemplateRegistry
{
    public const BASELINE_VERSION = 1;

    /** @var array<string, array<int, array<string, array{kind: 'text'|'date'|'textarea'|'boolean'}>>> */
    private const TEMPLATES = [
        'general' => [1 => [
            'cabeza' => ['kind' => 'textarea'], 'cuello' => ['kind' => 'textarea'],
            'torax' => ['kind' => 'textarea'], 'abdomen' => ['kind' => 'textarea'],
            'miembros' => ['kind' => 'textarea'], 'genito_urinario' => ['kind' => 'textarea'],
        ]],
        'gynecological' => [1 => [
            'menarca' => ['kind' => 'text'], 'fum' => ['kind' => 'date'],
            'ciclo' => ['kind' => 'text'], 'gestas' => ['kind' => 'text'],
            'partos' => ['kind' => 'text'], 'cesareas' => ['kind' => 'text'],
            'abortos' => ['kind' => 'text'], 'metodo_anticonceptivo' => ['kind' => 'text'],
            'papanicolaou' => ['kind' => 'text'], 'examen_mamario' => ['kind' => 'textarea'],
            'examen_pelvico' => ['kind' => 'textarea'],
        ], 2 => [
            // v1 stays readable forever. From v2 the head-to-toe segments are
            // part of the template instead of being replaced by it: choosing
            // "Ginecológica" used to make head, neck, chest and abdomen vanish
            // from the form, so the most frequent consultation in an obstetrics
            // practice was the one that lost the physical exam. A pregnant woman
            // also has a chest.
            'cabeza' => ['kind' => 'textarea'], 'cuello' => ['kind' => 'textarea'],
            'torax' => ['kind' => 'textarea'], 'abdomen' => ['kind' => 'textarea'],
            'miembros' => ['kind' => 'textarea'], 'genito_urinario' => ['kind' => 'textarea'],
            'menarca' => ['kind' => 'text'], 'fum' => ['kind' => 'date'],
            'ciclo' => ['kind' => 'text'], 'gestas' => ['kind' => 'text'],
            'partos' => ['kind' => 'text'], 'cesareas' => ['kind' => 'text'],
            'abortos' => ['kind' => 'text'], 'metodo_anticonceptivo' => ['kind' => 'text'],
            'papanicolaou' => ['kind' => 'text'], 'examen_mamario' => ['kind' => 'textarea'],
            'examen_pelvico' => ['kind' => 'textarea'],
        ]],
        'pediatric' => [1 => [
            'motor_grueso' => ['kind' => 'text'], 'motor_fino' => ['kind' => 'text'],
            'lenguaje' => ['kind' => 'text'], 'social' => ['kind' => 'text'],
            // Manual historical inputs only. OMS calculations belong to F2.
            'percentil_peso' => ['kind' => 'text'], 'percentil_talla' => ['kind' => 'text'],
            'perimetro_cefalico' => ['kind' => 'text'], 'desarrollo_observaciones' => ['kind' => 'textarea'],
        ], 2 => [
            // v1 stays readable forever. From v2, OMS measurements live in the
            // versioned server assessment, not a client-controlled text map.
            'motor_grueso' => ['kind' => 'text'], 'motor_fino' => ['kind' => 'text'],
            'lenguaje' => ['kind' => 'text'], 'social' => ['kind' => 'text'],
            'desarrollo_observaciones' => ['kind' => 'textarea'],
        ]],
        'prenatal' => [1 => [
            'edad_gestacional' => ['kind' => 'text'], 'altura_uterina' => ['kind' => 'text'],
            'fcf' => ['kind' => 'text'], 'presentacion' => ['kind' => 'text'],
            'movimientos_fetales' => ['kind' => 'text'], 'edemas' => ['kind' => 'text'],
            'observaciones_prenatal' => ['kind' => 'textarea'],
        ], 2 => [
            // Same composition as gynecological v2, and for the same reason.
            // `edad_gestacional` stays as a manual field: it is what a record
            // with no registered pregnancy still needs. When the pregnancy IS
            // registered the age is computed by GestationalAge and shown in the
            // header band — see ADR-009.
            'cabeza' => ['kind' => 'textarea'], 'cuello' => ['kind' => 'textarea'],
            'torax' => ['kind' => 'textarea'], 'abdomen' => ['kind' => 'textarea'],
            'miembros' => ['kind' => 'textarea'], 'genito_urinario' => ['kind' => 'textarea'],
            'edad_gestacional' => ['kind' => 'text'], 'altura_uterina' => ['kind' => 'text'],
            'fcf' => ['kind' => 'text'], 'presentacion' => ['kind' => 'text'],
            'movimientos_fetales' => ['kind' => 'text'], 'edemas' => ['kind' => 'text'],
            'observaciones_prenatal' => ['kind' => 'textarea'],
        ], 3 => [
            // v2 stays readable forever. v3 adds four yes/no checks that used
            // to only fit inside free-text "observaciones" — operational
            // record-keeping (was this asked, was this talked about), not a
            // diagnostic score, so it does not need the clinical-reviewer gate
            // ADR-010 sets for something like a risk score. The intimate-
            // partner-violence screen is the one item with a direct citation:
            // the MSP prenatal GPC lists it explicitly in the first-trimester
            // anamnesis (knowledge/reference/CARNE_PERINATAL.md). The GPC does
            // NOT describe a fixed once-per-trimester structure the way the
            // competitor being compared against does — copying that shape was
            // already flagged as a mistake for the screening catalog, so this
            // repeats per CONTROL instead of three fixed trimester slots.
            'cabeza' => ['kind' => 'textarea'], 'cuello' => ['kind' => 'textarea'],
            'torax' => ['kind' => 'textarea'], 'abdomen' => ['kind' => 'textarea'],
            'miembros' => ['kind' => 'textarea'], 'genito_urinario' => ['kind' => 'textarea'],
            'edad_gestacional' => ['kind' => 'text'], 'altura_uterina' => ['kind' => 'text'],
            'fcf' => ['kind' => 'text'], 'presentacion' => ['kind' => 'text'],
            'movimientos_fetales' => ['kind' => 'text'], 'edemas' => ['kind' => 'text'],
            'tamizaje_violencia_intrafamiliar' => ['kind' => 'boolean'],
            'tamizaje_tabaco_alcohol_drogas' => ['kind' => 'boolean'],
            'plan_parto_conversado' => ['kind' => 'boolean'],
            'consejeria_lactancia' => ['kind' => 'boolean'],
            'observaciones_prenatal' => ['kind' => 'textarea'],
        ]],
        'preventive' => [1 => [
            'agudeza_visual' => ['kind' => 'text'], 'agudeza_auditiva' => ['kind' => 'text'],
            'salud_bucal' => ['kind' => 'text'], 'estado_nutricional' => ['kind' => 'text'],
            'riesgo_cardiovascular' => ['kind' => 'text'], 'recomendaciones_preventivas' => ['kind' => 'textarea'],
        ]],
    ];

    public function currentVersion(string $consultationType): int
    {
        return max(array_keys(self::TEMPLATES[$consultationType]));
    }

    /**
     * @param  array<string, mixed>|null  $structuredExam
     * @param  list<string>  $existingKeys  Unknown legacy keys are preserved only on their existing record.
     */
    public function assertValid(string $consultationType, int $version, ?array $structuredExam, array $existingKeys = []): void
    {
        if ($structuredExam === null) {
            return;
        }

        $fields = self::TEMPLATES[$consultationType][$version] ?? null;
        if ($fields === null) {
            throw ValidationException::withMessages(['consultation_type' => ['La plantilla clínica de esta atención no está disponible.']]);
        }

        $existing = array_fill_keys($existingKeys, true);
        $errors = [];
        foreach ($structuredExam as $key => $value) {
            // Laravel's own ConvertEmptyStringsToNull middleware turns a field
            // cleared back to "" into null before this ever sees it — treated
            // the same as an empty string, not as a wrong type. Nothing else
            // in this array should ever legitimately be null.
            if (! is_string($key) || ($value !== null && ! is_string($value))) {
                $errors['structured_exam'] = ['Cada campo de exploración debe ser texto.'];

                continue;
            }
            $field = $fields[$key] ?? null;
            if ($field === null) {
                if (! isset($existing[$key])) {
                    $errors['structured_exam'] = ['La exploración incluye un campo que no pertenece a esta plantilla.'];
                }

                continue;
            }
            if ($field['kind'] === 'date' && $value !== null && $value !== '' && ! $this->isIsoDate($value)) {
                $errors["structured_exam.{$key}"] = ['La fecha debe usar el formato AAAA-MM-DD.'];
            }
            if ($field['kind'] === 'boolean' && $value !== null && ! in_array($value, ['', 'si', 'no'], true)) {
                $errors["structured_exam.{$key}"] = ['Este campo solo admite sí, no, o quedar sin registrar.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isIsoDate(string $value): bool
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }
}
