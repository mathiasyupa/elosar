<?php

use App\Services\Obstetrics\ScoreMama;

/** A complete, normal reading: every parameter in its 0 column. */
function normalVitals(array $over = []): array
{
    return array_merge([
        'temperature'         => 36.5,
        'blood_pressure_sys'  => 120,
        'blood_pressure_dia'  => 70,
        'heart_rate'          => 80,
        'respiratory_rate'    => 16,
        'oxygen_saturation'   => 97,
        'oxygen_therapy'      => 'ambient_air',
        'consciousness_level' => 'alert',
        'urine_output_ml_h'   => null,
        'urine_output_not_quantifiable' => true,
    ], $over);
}

/**
 * The one test that validates the whole table at once. The protocol prints this
 * case solved, with its per-parameter scores and its total. Reorder a column and
 * this stops adding up to 3.
 */
it('reproduces the worked example printed in the MSP protocol', function () {
    $result = ScoreMama::score([
        'temperature'         => 36.5,
        'blood_pressure_sys'  => 140,
        'blood_pressure_dia'  => 80,
        'heart_rate'          => 105,
        'respiratory_rate'    => 22,
        'oxygen_saturation'   => 96,
        'oxygen_therapy'      => 'ambient_air',
        'consciousness_level' => 'alert',
        'urine_output_not_quantifiable' => true,
    ]);

    expect($result['total'])->toBe(3)
        ->and($result['level'])->toBe('treat_and_refer')
        ->and($result['complete'])->toBeTrue()
        ->and($result['points'])->toEqual([
            'temperature'         => 0,
            'blood_pressure_sys'  => 1,
            'blood_pressure_dia'  => 0,
            'heart_rate'          => 1,
            'respiratory_rate'    => 1,
            'oxygen_saturation'   => 0,
            'oxygen_therapy'      => 0,
            'consciousness_level' => 0,
            'urine_output'        => 0,
        ]);
});

/** The easiest cell to get wrong: that row has no 2 on the cold side. */
it('scores a temperature of 35 to 35.9 as 1, not 2', function () {
    expect(ScoreMama::score(normalVitals(['temperature' => 35.5]))['points']['temperature'])->toBe(1)
        ->and(ScoreMama::score(normalVitals(['temperature' => 34.9]))['points']['temperature'])->toBe(3);
});

/** The rule that is not a sum. A systolic of 65 scores 3 on its own. */
it('escalates to emergency on any single parameter at 3, however low the total', function () {
    $result = ScoreMama::score(normalVitals(['blood_pressure_sys' => 65]));

    expect($result['total'])->toBe(3)
        ->and($result['has_parameter_at_three'])->toBeTrue()
        ->and($result['level'])->toBe('emergency');
});

it('escalates to emergency on 5 points with nothing at 3', function () {
    $result = ScoreMama::score(normalVitals([
        'heart_rate'          => 115,
        'respiratory_rate'    => 26,
        'consciousness_level' => 'responds_to_voice',
    ]));

    expect($result['total'])->toBe(5)
        ->and($result['has_parameter_at_three'])->toBeFalse()
        ->and($result['level'])->toBe('emergency');
});

/**
 * "Not quantifiable" is a value, not an absence: it is the NORMAL cell and scores
 * 0. Confusing the two would mark every outpatient's reading incomplete.
 */
it('treats a non-quantifiable urine output as a real 0, not as missing', function () {
    $result = ScoreMama::score(normalVitals());

    expect($result['points']['urine_output'])->toBe(0)
        ->and($result['complete'])->toBeTrue();
});

it('scores measured urine output against the oliguria cut-offs', function (float $mlh, int $expected) {
    $result = ScoreMama::score(normalVitals([
        'urine_output_ml_h' => $mlh,
        'urine_output_not_quantifiable' => false,
    ]));

    expect($result['points']['urine_output'])->toBe($expected);
})->with([[8, 3], [25, 2], [60, 0]]);

/**
 * A parameter nobody measured cannot score. Counting it as zero would lower the
 * score of a patient precisely where the missing measurement might be the problem.
 */
it('never counts a missing parameter as a zero', function () {
    $result = ScoreMama::score(['temperature' => 36.5, 'blood_pressure_sys' => 120]);

    expect($result['total'])->toBe(0)
        ->and($result['complete'])->toBeFalse()
        ->and($result['points']['consciousness_level'])->toBeNull()
        ->and($result['missing'])->toContain('consciousness_level');
});

it('scores each systolic band the way the table prints it', function (int $sys, int $expected) {
    expect(ScoreMama::score(normalVitals(['blood_pressure_sys' => $sys]))['points']['blood_pressure_sys'])
        ->toBe($expected);
})->with([[139, 0], [140, 1], [149, 1], [150, 2], [159, 2], [160, 3], [90, 0], [89, 1], [79, 2], [70, 3]]);

it('scores each oxygen saturation band the way the table prints it', function (int $spo2, int $expected) {
    expect(ScoreMama::score(normalVitals(['oxygen_saturation' => $spo2]))['points']['oxygen_saturation'])
        ->toBe($expected);
})->with([[94, 0], [93, 1], [90, 2], [88, 3]]);

it('scores oxygen therapy the way the table prints it', function (string $therapy, int $expected) {
    expect(ScoreMama::score(normalVitals(['oxygen_therapy' => $therapy]))['points']['oxygen_therapy'])
        ->toBe($expected);
})->with([['ambient_air', 0], ['nasal_cannula', 2], ['mask_or_higher', 3]]);

it('scores consciousness the way the table prints it', function (string $level, int $expected) {
    expect(ScoreMama::score(normalVitals(['consciousness_level' => $level]))['points']['consciousness_level'])
        ->toBe($expected);
})->with([['alert', 0], ['responds_to_voice', 1], ['responds_to_pain', 2], ['unresponsive', 3]]);

/**
 * The stored score carries which protocol produced it. Without this, a future
 * MSP revision would silently re-score every reading already in the history.
 */
it('stamps the protocol version it scored with', function () {
    expect(ScoreMama::score(normalVitals())['protocol'])->toBe('MSP-2025');
});
