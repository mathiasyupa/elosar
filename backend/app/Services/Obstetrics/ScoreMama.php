<?php

namespace App\Services\Obstetrics;

/**
 * Score MAMÁ — Ecuador's MSP maternal early-warning score.
 *
 * The authority. The web recomputes the same table so the nurse sees the alert
 * while she is still taking the reading, but what gets stored is scored HERE,
 * from the values the server received. A client is a display, not a source of
 * clinical truth: a stale bundle must never be able to write a wrong alert into
 * a medical record.
 *
 * The table is the 2025 protocol's (Acuerdo Ministerial 00042-2025), transcribed
 * in `knowledge/reference/SCORE_MAMA.md` — reconstructed from the PDF by cell
 * coordinates, then validated against the worked example the protocol prints.
 * Change a cut-off there first and repeat that validation; never tune it here.
 *
 * Kept deliberately in step with `medico-web/src/features/encounters/scoreMama.ts`.
 * Both suites assert the protocol's worked example, so if one drifts the other
 * keeps telling the truth and a test goes red.
 */
final class ScoreMama
{
    /** Stamped on every stored score so a future revision cannot re-score history. */
    public const PROTOCOL = 'MSP-2025';

    public const OXYGEN_THERAPY = ['ambient_air', 'nasal_cannula', 'mask_or_higher'];

    public const CONSCIOUSNESS = ['alert', 'responds_to_voice', 'responds_to_pain', 'unresponsive'];

    public const LEVELS = ['normal', 'reevaluate', 'treat_and_refer', 'emergency'];

    private const OXYGEN_THERAPY_POINTS = [
        'ambient_air'    => 0,
        'nasal_cannula'  => 2,
        'mask_or_higher' => 3,
    ];

    private const CONSCIOUSNESS_POINTS = [
        'alert'             => 0,
        'responds_to_voice' => 1,
        'responds_to_pain'  => 2,
        'unresponsive'      => 3,
    ];

    /**
     * Scores one vitals reading.
     *
     * Two decisions that are not obvious and that matter clinically:
     *
     * 1. A parameter nobody took does not score zero — it does not score. Zero
     *    would lower the score of a patient precisely where nothing was measured.
     *    The result reports what is missing so the caller knows what it is worth.
     * 2. The top band is not just >= 5. Any single parameter at 3 is an obstetric
     *    emergency even when the total is 3.
     *
     * @param  array<string, mixed>  $vitals
     * @return array{total:int, points:array<string,int|null>, missing:list<string>, complete:bool, has_parameter_at_three:bool, level:string, protocol:string}
     */
    public static function score(array $vitals): array
    {
        $points = [
            'temperature'         => self::bands($vitals['temperature'] ?? null, [
                [34.99, 3], [35.9, 1], [37.4, 0], [38.2, 1], [38.9, 2],
            ], 3),
            'blood_pressure_sys'  => self::bands($vitals['blood_pressure_sys'] ?? null, [
                [70, 3], [79, 2], [89, 1], [139, 0], [149, 1], [159, 2],
            ], 3),
            // No 3 or 2 on the low side: an isolated low diastolic scores 1.
            'blood_pressure_dia'  => self::bands($vitals['blood_pressure_dia'] ?? null, [
                [49, 1], [89, 0], [99, 1], [109, 2],
            ], 3),
            'heart_rate'          => self::bands($vitals['heart_rate'] ?? null, [
                [40, 3], [49, 2], [99, 0], [109, 1], [129, 2],
            ], 3),
            'respiratory_rate'    => self::bands($vitals['respiratory_rate'] ?? null, [
                [10, 3], [20, 0], [24, 1], [29, 2],
            ], 3),
            // Low side only: saturating well is not penalised.
            'oxygen_saturation'   => self::bands($vitals['oxygen_saturation'] ?? null, [
                [88, 3], [90, 2], [93, 1],
            ], 0),
            'oxygen_therapy'      => self::mapped($vitals['oxygen_therapy'] ?? null, self::OXYGEN_THERAPY_POINTS),
            'consciousness_level' => self::mapped($vitals['consciousness_level'] ?? null, self::CONSCIOUSNESS_POINTS),
            'urine_output'        => self::urineOutput($vitals),
        ];

        $total = 0;
        $missing = [];
        $hasThree = false;

        foreach ($points as $key => $value) {
            if ($value === null) {
                // The reading, not the column: urine output is one parameter
                // stored across two fields.
                $missing[] = $key;

                continue;
            }

            $total += $value;
            $hasThree = $hasThree || $value === 3;
        }

        return [
            'total'                  => $total,
            'points'                 => $points,
            'missing'                => $missing,
            'complete'               => $missing === [],
            'has_parameter_at_three' => $hasThree,
            'level'                  => self::level($total, $hasThree),
            'protocol'               => self::PROTOCOL,
        ];
    }

    /**
     * The subset a caller should persist on `record_vitals`. Null score when the
     * reading has nothing scoreable in it, so an empty row does not claim a 0.
     *
     * @param  array<string, mixed>  $vitals
     * @return array{score_mama_total:int|null, score_mama_level:string|null, score_mama_protocol:string|null}
     */
    public static function columnsFor(array $vitals): array
    {
        $result = self::score($vitals);

        if (count($result['missing']) === count($result['points'])) {
            return [
                'score_mama_total'    => null,
                'score_mama_level'    => null,
                'score_mama_protocol' => null,
            ];
        }

        return [
            'score_mama_total'    => $result['total'],
            'score_mama_level'    => $result['level'],
            'score_mama_protocol' => $result['protocol'],
        ];
    }

    /**
     * Urine output is one parameter held in two columns: a measured value, or the
     * "not quantifiable" state that the table prints in the NORMAL column and
     * scores 0. Absent is neither.
     *
     * @param  array<string, mixed>  $vitals
     */
    private static function urineOutput(array $vitals): ?int
    {
        if (! empty($vitals['urine_output_not_quantifiable'])) {
            return 0;
        }

        $value = $vitals['urine_output_ml_h'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        // The protocol prints <=10 -> 3 and <=30 -> 2 and NO cell for a measured
        // output above 30. That scores 0 from the clinical oliguria cut-off
        // (<30 mL/h), which is the column "not quantifiable" lives in. It is an
        // inference, not something the MSP printed; noted in SCORE_MAMA.md.
        return self::bands((float) $value, [[10, 3], [30, 2]], 0);
    }

    /**
     * @param  list<array{0:float|int, 1:int}>  $bands
     */
    private static function bands(mixed $value, array $bands, int $above): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (float) $value;

        foreach ($bands as [$max, $points]) {
            if ($value <= $max) {
                return $points;
            }
        }

        return $above;
    }

    /**
     * @param  array<string, int>  $table
     */
    private static function mapped(mixed $value, array $table): ?int
    {
        return is_string($value) ? ($table[$value] ?? null) : null;
    }

    private static function level(int $total, bool $hasParameterAtThree): string
    {
        if ($total >= 5 || $hasParameterAtThree) {
            return 'emergency';
        }

        if ($total >= 2) {
            return 'treat_and_refer';
        }

        return $total === 1 ? 'reevaluate' : 'normal';
    }
}
