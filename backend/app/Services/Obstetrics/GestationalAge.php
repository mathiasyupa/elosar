<?php

namespace App\Services\Obstetrics;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Gestational age and estimated due date.
 *
 * The authority. The web recomputes the same arithmetic so the doctor sees the
 * age while typing, but what gets stored is computed HERE. A client is a display,
 * not a source of clinical truth.
 *
 * What is persisted and what is not, because the distinction matters:
 *
 *   - The DUE DATE is stored (`pregnancies.edd_date` + `edd_protocol`). It is a
 *     dating DECISION, not a display value: it has to be stable, sortable and
 *     indexable — the probable-deliveries report orders by it — and a future
 *     change of rule must not silently re-date pregnancies already lived. Same
 *     reasoning as stamping the protocol on every Score MAMÁ row.
 *   - TODAY'S AGE is never stored. It changes every morning; storing it
 *     guarantees a stale number on screen.
 *   - The age OF A CONTROL is frozen on that control, because it is the age the
 *     finding was read against. The current age is recomputed separately, and the
 *     UI must label the two differently or it misleads whoever is attending.
 *
 * Deliberately NOT done here: choosing between the last menstrual period and the
 * scan on its own. The MSP prenatal guideline states that dating by LMP carries
 * an 11-22% error and recommends a scan at 11-14 weeks, but it publishes no
 * re-dating threshold — how many days of discrepancy, per trimester, force the
 * scan to win (see knowledge/reference/CARNE_PERINATAL.md). Inventing that number
 * here is exactly what ScoreMama's docblock forbids. So both due dates are
 * computed, the discrepancy is reported, and `dating_source` is the clinician's.
 *
 * Kept deliberately in step with `medico-web/src/features/obstetrics/gestationalAge.ts`;
 * both suites assert the same worked cases, so if one drifts a test goes red.
 */
final class GestationalAge
{
    /** Stamped on every stored due date so a future rule cannot re-date history. */
    public const PROTOCOL = 'NAEGELE-280';

    public const DATING_SOURCES = ['lmp', 'ultrasound', 'manual'];

    /** A full-term pregnancy, counted from the first day of the last period. */
    private const TERM_DAYS = 280;

    private const TERM_FROM_DAYS = 259;      // 37+0

    private const POST_TERM_FROM_DAYS = 294; // 42+0

    /** Naegele: the due date is 280 days after the first day of the last period. */
    public static function eddFromLmp(CarbonInterface $lmp): CarbonImmutable
    {
        return CarbonImmutable::parse($lmp)->startOfDay()->addDays(self::TERM_DAYS);
    }

    /**
     * A scan dates the pregnancy at the moment it was taken: if the fetus measured
     * `w+d` on that day, term is whatever is left of the 280.
     */
    public static function eddFromUltrasound(CarbonInterface $takenOn, int $weeks, int $days = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($takenOn)->startOfDay()
            ->addDays(self::TERM_DAYS - ($weeks * 7 + $days));
    }

    /**
     * Probable date for an elective repeat cesarean: 39 weeks, one less than the
     * due date.
     *
     * Not scheduled earlier, to avoid the newborn's respiratory morbidity that
     * comes with a cesarean before 39 weeks; not left to the due date itself,
     * to avoid spontaneous labour starting first. Confirmed with the referring
     * clinician against two real examples from the system being migrated from,
     * both exactly 7 days apart between the due date and this one — not found
     * written down as an explicit rule anywhere reviewed, so it rests on that
     * confirmation rather than a cited guideline. A calendar estimate, not a
     * decision: it says when 39 weeks falls, never whether a cesarean is right
     * for this pregnancy.
     */
    public static function electiveCesareanDate(?CarbonInterface $edd): ?CarbonImmutable
    {
        return $edd === null ? null : CarbonImmutable::parse($edd)->startOfDay()->subDays(7);
    }

    /**
     * Which dating rules, what the other one would have said, and how far apart
     * they are. Never decides on its own — `dating_source` does.
     *
     * `discrepancy_days` is signed: positive means the scan dates the pregnancy
     * further along than the last period does.
     *
     * @param  array<string, mixed>  $pregnancy
     * @return array{edd:?CarbonImmutable, source:?string, edd_by_lmp:?CarbonImmutable, edd_by_ultrasound:?CarbonImmutable, discrepancy_days:?int, missing:list<string>, protocol:string}
     */
    public static function resolve(array $pregnancy): array
    {
        $byLmp = self::parseDate($pregnancy['lmp_date'] ?? null);
        $byLmp = $byLmp ? self::eddFromLmp($byLmp) : null;

        $scanOn = self::parseDate($pregnancy['ultrasound_date'] ?? null);
        $weeks = $pregnancy['ultrasound_weeks'] ?? null;
        $byScan = ($scanOn !== null && $weeks !== null && $weeks !== '')
            ? self::eddFromUltrasound($scanOn, (int) $weeks, (int) ($pregnancy['ultrasound_days'] ?? 0))
            : null;

        $missing = [];
        if ($byLmp === null) {
            $missing[] = 'lmp_date';
        }
        if ($byScan === null) {
            $missing[] = 'ultrasound';
        }

        $source = $pregnancy['dating_source'] ?? 'lmp';
        $source = in_array($source, self::DATING_SOURCES, true) ? $source : 'lmp';

        // Fall back to whichever dating actually exists rather than reporting no
        // due date at all: a pregnancy dated only by scan, with `dating_source`
        // still on its default, still has a due date. The source reported back is
        // the one actually used, not the one requested — they differ exactly when
        // the requested dating has no data behind it.
        [$edd, $effectiveSource] = match (true) {
            $source === 'ultrasound' && $byScan !== null => [$byScan, 'ultrasound'],
            $byLmp !== null                              => [$byLmp, 'lmp'],
            $byScan !== null                             => [$byScan, 'ultrasound'],
            default                                      => [null, null],
        };

        return [
            'edd'               => $edd,
            'source'            => $effectiveSource,
            'edd_by_lmp'        => $byLmp,
            'edd_by_ultrasound' => $byScan,
            // Scan earlier due date => fetus measured older than the period says.
            'discrepancy_days'  => ($byLmp && $byScan) ? (int) $byScan->diffInDays($byLmp, false) : null,
            'missing'           => $missing,
            'protocol'          => self::PROTOCOL,
        ];
    }

    /**
     * The gestational age on a given day.
     *
     * Two rules carried over from ScoreMama, for the same reason: a pregnancy with
     * nothing to date it does NOT come back as zero weeks — it comes back null,
     * saying what is missing. And an impossible input (a period in the future)
     * comes back invalid rather than as a negative age.
     *
     * A closed pregnancy freezes at its outcome date: the age of a pregnancy that
     * ended is not still growing.
     *
     * @param  array<string, mixed>  $pregnancy
     * @return array{days:?int, weeks:?int, remainder_days:?int, label:?string, trimester:?int, edd:?string, source:?string, is_term:bool, is_post_term:bool, is_valid:bool, missing:list<string>, protocol:string}
     */
    public static function at(array $pregnancy, ?CarbonInterface $on = null): array
    {
        $resolved = self::resolve($pregnancy);

        // Reduce to the civil date and rebuild, so a caller in UTC and a caller
        // in America/Guayaquil get the same age for the same day. Diffing two
        // instants five hours apart truncates to different day counts, and that
        // is the write/read asymmetry this codebase has already been bitten by.
        $on = CarbonImmutable::parse(
            CarbonImmutable::parse($on ?? CarbonImmutable::now())->toDateString()
        );

        $outcomeDate = self::parseDate($pregnancy['outcome_date'] ?? null);
        if (($pregnancy['status'] ?? 'active') !== 'active' && $outcomeDate !== null) {
            $on = $outcomeDate;
        }

        $edd = $resolved['edd'];

        $empty = [
            'days'           => null,
            'weeks'          => null,
            'remainder_days' => null,
            'label'          => null,
            'trimester'      => null,
            'edd'            => $edd?->toDateString(),
            'source'         => $resolved['source'],
            'is_term'        => false,
            'is_post_term'   => false,
            'is_valid'       => false,
            'missing'        => $resolved['missing'],
            'protocol'       => self::PROTOCOL,
        ];

        if ($edd === null) {
            return $empty;
        }

        // Counting back from the due date works for both dating sources without
        // needing a separate conception date.
        $days = self::TERM_DAYS - (int) $on->diffInDays($edd, false);

        if ($days < 0) {
            return $empty;
        }

        return [
            'days'           => $days,
            'weeks'          => intdiv($days, 7),
            'remainder_days' => $days % 7,
            'label'          => intdiv($days, 7) . '+' . ($days % 7),
            'trimester'      => match (true) {
                $days < 98  => 1,   // < 14+0
                $days < 196 => 2,   // < 28+0
                default     => 3,
            },
            'edd'            => $edd->toDateString(),
            'source'         => $resolved['source'],
            'is_term'        => $days >= self::TERM_FROM_DAYS,
            'is_post_term'   => $days >= self::POST_TERM_FROM_DAYS,
            'is_valid'       => true,
            'missing'        => $resolved['missing'],
            'protocol'       => self::PROTOCOL,
        ];
    }

    /**
     * The subset a caller persists on `pregnancies`. Null due date when there is
     * nothing to date it with, so an empty row does not claim one.
     *
     * @param  array<string, mixed>  $pregnancy
     * @return array{edd_date:?string, edd_protocol:?string}
     */
    public static function columnsFor(array $pregnancy): array
    {
        $edd = self::resolve($pregnancy)['edd'];

        return [
            'edd_date'     => $edd?->toDateString(),
            'edd_protocol' => $edd === null ? null : self::PROTOCOL,
        ];
    }

    /**
     * The subset a caller persists on `pregnancy_controls` — the age this visit
     * was read against, frozen with the dating that produced it.
     *
     * @param  array<string, mixed>  $pregnancy
     * @return array{gestational_age_days:?int, dating_source:?string, dating_protocol:?string}
     */
    public static function controlColumnsFor(array $pregnancy, ?CarbonInterface $on = null): array
    {
        $age = self::at($pregnancy, $on);

        return [
            'gestational_age_days' => $age['days'],
            'dating_source'        => $age['days'] === null ? null : $age['source'],
            'dating_protocol'      => $age['days'] === null ? null : self::PROTOCOL,
        ];
    }

    private static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            // Civil dates: a due date must not shift because the server and the
            // clinic disagree about the hour. Reduce to Y-m-d and rebuild, so
            // everything downstream is midnight in one single timezone.
            return CarbonImmutable::parse(CarbonImmutable::parse($value)->toDateString());
        } catch (\Exception) {
            return null;
        }
    }
}
