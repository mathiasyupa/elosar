<?php

namespace App\Services\Obstetrics;

/**
 * The prenatal screening schedule, as the MSP publishes it.
 *
 * Transcribed from the *Guía de Práctica Clínica «Control Prenatal»*, Ministerio
 * de Salud Pública del Ecuador, Dirección Nacional de Normatización, May 2016 —
 * sections 10.7 (activities by gestational week), 10.8 (laboratory tests) and
 * 10.9 (ultrasound). Transcribed and cross-checked in
 * `knowledge/reference/CARNE_PERINATAL.md`. **Change a code or a moment there
 * first; never tune it here.**
 *
 * Two things this catalogue does that a fixed set of columns cannot:
 *
 *  1. It distinguishes UNIVERSAL screening from screening BY RISK FACTOR, which
 *     the guideline does for almost every test. The system Ecuadorian clinics use
 *     today offers group B strep as a plain checkbox for every pregnancy; the MSP
 *     recommends it at 35-37 weeks *only where other risk factors are present*.
 *     Copying the competitor would have shipped a practice the national guideline
 *     does not support.
 *  2. It carries the reason a test is not offered. The guideline explicitly says
 *     NOT to screen asymptomatic vaginal infection (R-A), so that box does not
 *     exist — the product should not invite a practice the norm advises against.
 */
final class PrenatalScreenings
{
    public const SOURCE = 'MSP-GPC-CPN-2016';

    /**
     * code => [label, when, universal]
     *
     * `universal` false means the guideline asks for it only on a risk factor,
     * and the UI must say so rather than presenting an empty box as an omission.
     *
     * @var array<string, array{label: string, when: string, universal: bool}>
     */
    private const CATALOGUE = [
        'blood_type_rh' => [
            'label'     => 'Tipología sanguínea, Rh y Coombs indirecto',
            'when'      => 'Primer control. Repetir a las 28 semanas en Rh negativas no sensibilizadas',
            'universal' => true,
        ],
        'hemoglobin' => [
            'label'     => 'Hemoglobina y hematocrito',
            'when'      => 'Primer control de cada embarazo',
            'universal' => true,
        ],
        'fasting_glucose' => [
            'label'     => 'Glicemia en ayunas',
            'when'      => 'Consulta médica inicial',
            'universal' => true,
        ],
        'ogtt_75' => [
            'label'     => 'PTOG 75 g',
            'when'      => 'Semanas 24 a 28, si la glicemia inicial fue menor a 92 mg/dL',
            'universal' => false,
        ],
        'hiv' => [
            'label'     => 'VIH',
            'when'      => 'Primer control y de nuevo entre las 24 y 34 semanas',
            'universal' => true,
        ],
        'hepatitis_b' => [
            'label'     => 'Hepatitis B (antígeno de superficie)',
            'when'      => 'Primera consulta prenatal, a todas',
            'universal' => true,
        ],
        'hepatitis_c' => [
            'label'     => 'Hepatitis C',
            'when'      => 'Sólo con factores de riesgo (drogas IV, hemodiálisis, AST elevadas, receptor de sangre u órganos, privación de libertad, tatuajes)',
            'universal' => false,
        ],
        'vdrl' => [
            'label'     => 'VDRL (sífilis), confirmatoria FTA-abs',
            'when'      => 'Primer control de cada embarazo. Repetir ante conducta de riesgo',
            'universal' => true,
        ],
        'rubella_igg' => [
            'label'     => 'Anticuerpos de rubeola',
            'when'      => 'Sólo si no se conoce historia de la enfermedad ni de inmunización',
            'universal' => false,
        ],
        'urine_culture' => [
            'label'     => 'Urocultivo (bacteriuria asintomática)',
            'when'      => 'Etapa temprana. Cada trimestre si hay historia de ITU recurrente',
            'universal' => true,
        ],
        'tsh' => [
            'label'     => 'TSH',
            'when'      => 'Sólo con historia o síntomas de enfermedad tiroidea',
            'universal' => false,
        ],
        'toxoplasmosis' => [
            'label'     => 'Toxoplasmosis',
            'when'      => 'Embarazadas de riesgo por exposición',
            'universal' => false,
        ],
        'chagas' => [
            'label'     => 'Enfermedad de Chagas',
            'when'      => 'Embarazadas de riesgo por exposición — el Ecuador tiene zonas endémicas',
            'universal' => false,
        ],
        'cervical_cytology' => [
            'label'     => 'Citología cérvico-vaginal',
            'when'      => 'Misma frecuencia e indicaciones que en la mujer no embarazada',
            'universal' => true,
        ],
        'group_b_strep' => [
            'label'     => 'Estreptococo grupo B (cultivo recto-vaginal)',
            'when'      => 'Semanas 35 a 37, SÓLO en pacientes con otros factores de riesgo',
            'universal' => false,
        ],
        'dental_exam' => [
            'label'     => 'Examen odontológico',
            'when'      => 'Primer trimestre',
            'universal' => true,
        ],
        'ultrasound_dating' => [
            'label'     => 'Ecografía de datación',
            'when'      => 'Semanas 11 a 14, por longitud cráneo-caudal',
            'universal' => true,
        ],
        'ultrasound_morphology' => [
            'label'     => 'Ecografía morfológica',
            'when'      => 'Semanas 18 a 24, óptima a las 22',
            'universal' => true,
        ],
        'proteinuria_24h' => [
            'label'     => 'Proteinuria en 24 horas',
            'when'      => 'Segundo trimestre, junto con la TA, para detectar preeclampsia',
            'universal' => true,
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /** @return array<string, array{label: string, when: string, universal: bool}> */
    public static function all(): array
    {
        return self::CATALOGUE;
    }

    public static function labelFor(string $code): ?string
    {
        return self::CATALOGUE[$code]['label'] ?? null;
    }

    public static function isUniversal(string $code): bool
    {
        return self::CATALOGUE[$code]['universal'] ?? false;
    }
}
