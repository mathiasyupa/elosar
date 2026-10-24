<?php

use App\Services\Obstetrics\GestationalAge;
use Carbon\CarbonImmutable;

/**
 * Every case fixes both the day the pregnancy started and the day it is being
 * read, so none of these can pass or fail depending on when the suite runs.
 */
function pregnancyDatedByLmp(string $lmp, array $over = []): array
{
    return array_merge(['lmp_date' => $lmp, 'dating_source' => 'lmp'], $over);
}

it('puts the due date 280 days after the first day of the last period', function () {
    // 2026-01-01 + 280 d = 2026-10-08.
    expect(GestationalAge::eddFromLmp(CarbonImmutable::parse('2026-01-01'))->toDateString())
        ->toBe('2026-10-08');
});

it('reports gestational age in weeks AND days, the way it is written on a chart', function () {
    // Exactly 228 days after the last period: 32 weeks and 4 days.
    $age = GestationalAge::at(pregnancyDatedByLmp('2026-01-01'), CarbonImmutable::parse('2026-08-17'));

    expect($age['days'])->toBe(228)
        ->and($age['weeks'])->toBe(32)
        ->and($age['remainder_days'])->toBe(4)
        ->and($age['label'])->toBe('32+4');
});

/** The boundary a "weeks only" implementation gets wrong without noticing. */
it('rolls over to the next week on the exact day, not before', function () {
    $lmp = '2026-01-01';

    // Day 223 is 31+6; day 224 is 32+0.
    $before = GestationalAge::at(pregnancyDatedByLmp($lmp), CarbonImmutable::parse('2026-01-01')->addDays(223));
    $on = GestationalAge::at(pregnancyDatedByLmp($lmp), CarbonImmutable::parse('2026-01-01')->addDays(224));

    expect($before['label'])->toBe('31+6')
        ->and($on['label'])->toBe('32+0');
});

it('dates from an early scan by counting the remainder of the 280 days', function () {
    // A fetus measuring 12+0 on 2026-03-01 has 280 - 84 = 196 days left.
    expect(GestationalAge::eddFromUltrasound(CarbonImmutable::parse('2026-03-01'), 12, 0)->toDateString())
        ->toBe(CarbonImmutable::parse('2026-03-01')->addDays(196)->toDateString());
});

it('reports the discrepancy between the two datings, signed', function () {
    $resolved = GestationalAge::resolve([
        // LMP dating: due 2026-10-08.
        'lmp_date'         => '2026-01-01',
        // Scan on 2026-03-01 measuring 10+0 => due 2026-03-01 + 210 = 2026-09-27,
        // i.e. the fetus is further along than the period says.
        'ultrasound_date'  => '2026-03-01',
        'ultrasound_weeks' => 10,
        'ultrasound_days'  => 0,
        'dating_source'    => 'lmp',
    ]);

    expect($resolved['edd_by_lmp']->toDateString())->toBe('2026-10-08')
        ->and($resolved['edd_by_ultrasound']->toDateString())->toBe('2026-09-27')
        // Positive: the scan dates the pregnancy further along.
        ->and($resolved['discrepancy_days'])->toBe(11);
});

/**
 * The decision the product refuses to make on its own. The MSP guideline says
 * LMP dating carries an 11-22% error, but publishes no re-dating threshold, so
 * the clinician chooses and the service obeys.
 */
it('obeys the clinician instead of re-dating on its own', function () {
    $pregnancy = [
        'lmp_date'         => '2026-01-01',
        'ultrasound_date'  => '2026-03-01',
        'ultrasound_weeks' => 10,
        'ultrasound_days'  => 0,
    ];

    $byLmp = GestationalAge::resolve([...$pregnancy, 'dating_source' => 'lmp']);
    $byScan = GestationalAge::resolve([...$pregnancy, 'dating_source' => 'ultrasound']);

    expect($byLmp['edd']->toDateString())->toBe('2026-10-08')
        ->and($byLmp['source'])->toBe('lmp')
        ->and($byScan['edd']->toDateString())->toBe('2026-09-27')
        ->and($byScan['source'])->toBe('ultrasound');
});

it('falls back to the dating that exists when the requested one has no data', function () {
    $resolved = GestationalAge::resolve([
        'ultrasound_date'  => '2026-03-01',
        'ultrasound_weeks' => 10,
        // Asks for LMP dating, but there is no last period on file.
        'dating_source'    => 'lmp',
    ]);

    expect($resolved['edd'])->not->toBeNull()
        // Reports what it actually used, not what it was asked for.
        ->and($resolved['source'])->toBe('ultrasound')
        ->and($resolved['missing'])->toBe(['lmp_date']);
});

it('splits the trimesters on their exact days', function () {
    $lmp = CarbonImmutable::parse('2026-01-01');
    $at = fn (int $days) => GestationalAge::at(pregnancyDatedByLmp('2026-01-01'), $lmp->addDays($days))['trimester'];

    expect($at(97))->toBe(1)   // 13+6
        ->and($at(98))->toBe(2) // 14+0
        ->and($at(195))->toBe(2) // 27+6
        ->and($at(196))->toBe(3); // 28+0
});

/**
 * Straight from ScoreMama: a parameter nobody supplied does not score zero. A
 * pregnancy with no dating is not "0 weeks pregnant" — it is undated.
 */
it('does not report zero weeks for a pregnancy it cannot date', function () {
    $age = GestationalAge::at(['dating_source' => 'lmp']);

    expect($age['days'])->toBeNull()
        ->and($age['weeks'])->toBeNull()
        ->and($age['label'])->toBeNull()
        ->and($age['is_valid'])->toBeFalse()
        ->and($age['missing'])->toBe(['lmp_date', 'ultrasound']);
});

it('never reports a negative age for a last period in the future', function () {
    $age = GestationalAge::at(
        pregnancyDatedByLmp('2026-06-01'),
        CarbonImmutable::parse('2026-05-01')
    );

    expect($age['days'])->toBeNull()
        ->and($age['is_valid'])->toBeFalse();
});

it('freezes the age of a pregnancy that already ended', function () {
    $ended = [
        'lmp_date'      => '2026-01-01',
        'dating_source' => 'lmp',
        'status'        => 'completed',
        'outcome_date'  => '2026-09-01',
    ];

    // Read months after the outcome: the age must not have kept growing.
    $onOutcome = GestationalAge::at($ended, CarbonImmutable::parse('2026-09-01'));
    $muchLater = GestationalAge::at($ended, CarbonImmutable::parse('2027-03-01'));

    expect($muchLater['days'])->toBe($onOutcome['days'])
        ->and($muchLater['label'])->toBe($onOutcome['label']);
});

it('marks term at 37+0 and post-term at 42+0, exactly', function () {
    $lmp = CarbonImmutable::parse('2026-01-01');
    $at = fn (int $days) => GestationalAge::at(pregnancyDatedByLmp('2026-01-01'), $lmp->addDays($days));

    expect($at(258)['is_term'])->toBeFalse()   // 36+6
        ->and($at(259)['is_term'])->toBeTrue()  // 37+0
        ->and($at(293)['is_post_term'])->toBeFalse() // 41+6
        ->and($at(294)['is_post_term'])->toBeTrue(); // 42+0
});

it('stamps the protocol it dated with', function () {
    $columns = GestationalAge::columnsFor(pregnancyDatedByLmp('2026-01-01'));

    expect($columns['edd_date'])->toBe('2026-10-08')
        ->and($columns['edd_protocol'])->toBe(GestationalAge::PROTOCOL);
});

it('leaves the due date null rather than inventing one for an undated pregnancy', function () {
    expect(GestationalAge::columnsFor(['dating_source' => 'lmp']))
        ->toBe(['edd_date' => null, 'edd_protocol' => null]);
});

/**
 * Confirmed with the referring clinician against two real examples from the
 * system being migrated from, both exactly 7 days apart between the due date
 * and the elective-cesarean estimate.
 */
it('puts the elective cesarean date 7 days before the due date', function () {
    expect(GestationalAge::electiveCesareanDate(CarbonImmutable::parse('2027-02-19'))->toDateString())
        ->toBe('2027-02-12');
});

it('does not invent a cesarean date with no due date to count back from', function () {
    expect(GestationalAge::electiveCesareanDate(null))->toBeNull();
});

it('freezes the age of a control together with the dating that produced it', function () {
    $columns = GestationalAge::controlColumnsFor(
        pregnancyDatedByLmp('2026-01-01'),
        CarbonImmutable::parse('2026-08-17')
    );

    expect($columns['gestational_age_days'])->toBe(228)
        ->and($columns['dating_source'])->toBe('lmp')
        ->and($columns['dating_protocol'])->toBe(GestationalAge::PROTOCOL);
});

/**
 * A due date is a civil date. If it shifted because the server and the clinic
 * disagree about the hour, every list ordered by it would be a day out — the
 * same 5-hour write/read asymmetry that already bites this codebase elsewhere.
 */
it('treats dates as civil dates, whatever the clock says', function () {
    $utc = GestationalAge::at(
        pregnancyDatedByLmp('2026-01-01'),
        CarbonImmutable::parse('2026-08-17 03:00:00', 'UTC')
    );
    $guayaquil = GestationalAge::at(
        pregnancyDatedByLmp('2026-01-01'),
        CarbonImmutable::parse('2026-08-17 22:00:00', 'America/Guayaquil')
    );

    expect($guayaquil['days'])->toBe($utc['days']);
});
