<?php

namespace App\Services\Clinical;

use App\Models\Patient;
use App\Models\RecordVital;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * WHO Child Growth Standards 2006 (0–60 completed months) calculator.
 *
 * The six official expanded WHO spreadsheets are shipped unchanged under
 * resources/clinical/who-2006. They contain a row per age day, including LMS
 * values and the ±2/±3 SD limits needed for the WHO adjusted extreme-score
 * formula. The browser never calculates or chooses a reference version.
 */
class WhoChildGrowthAssessment
{
    public const STANDARD = 'WHO_CHILD_GROWTH_STANDARDS';

    public const VERSION = 'WHO-2006';

    public const SOURCE_URL = 'https://www.who.int/tools/child-growth-standards/standards';

    public const MAX_AGE_DAYS = 1856;

    /** @var array<string, array<string, string>> */
    private const TABLES = [
        'weight_for_age' => [
            'female' => 'wfa-female.xlsx',
            'male' => 'wfa-male.xlsx',
        ],
        'length_height_for_age' => [
            'female' => 'lhfa-female.xlsx',
            'male' => 'lhfa-male.xlsx',
        ],
        'head_circumference_for_age' => [
            'female' => 'hcfa-female.xlsx',
            'male' => 'hcfa-male.xlsx',
        ],
    ];

    /** @var array<string, array<int, array<string, float>>> */
    private static array $cache = [];

    /**
     * @return array{
     *   status: 'calculated'|'not_calculable', reason: string|null,
     *   measurement_date: string|null, age_days: int|null, sex: string|null,
     *   weight_kg: float|null, length_height_cm: float|null,
     *   length_height_adjusted_cm: float|null,
     *   length_height_measurement_position: string|null,
     *   head_circumference_cm: float|null, table_checksums: array<string, string>,
     *   indicators: array<string, array<string, float|string|null>>
     * }
     */
    public function assess(Patient $patient, RecordVital $vital): array
    {
        $snapshot = [
            'status' => 'not_calculable',
            'reason' => null,
            'measurement_date' => $vital->recorded_at?->toDateString(),
            'age_days' => null,
            'sex' => null,
            'weight_kg' => $vital->weight_kg === null ? null : (float) $vital->weight_kg,
            'length_height_cm' => $vital->height_cm === null ? null : (float) $vital->height_cm,
            'length_height_adjusted_cm' => null,
            'length_height_measurement_position' => $vital->length_height_measurement_position,
            'head_circumference_cm' => $vital->head_circumference_cm === null ? null : (float) $vital->head_circumference_cm,
            'table_checksums' => $this->checksums(),
            'indicators' => [],
        ];

        $reason = $this->globalReason($patient, $vital);
        if ($reason !== null) {
            $snapshot['reason'] = $reason;
            $snapshot['indicators'] = $this->unavailableIndicators($reason);

            return $snapshot;
        }

        $measurementDate = CarbonImmutable::parse($vital->recorded_at)->startOfDay();
        $birthDate = CarbonImmutable::parse($patient->birth_date)->startOfDay();
        $ageDays = $birthDate->diffInDays($measurementDate);
        $sex = $patient->gender;
        $snapshot['age_days'] = $ageDays;
        $snapshot['sex'] = $sex;

        $weight = $snapshot['weight_kg'];
        $height = $snapshot['length_height_cm'];
        $headCircumference = $snapshot['head_circumference_cm'];
        $position = $snapshot['length_height_measurement_position'];

        $indicators = [
            'weight_for_age' => $weight === null
                ? $this->unavailable('measurement_missing')
                : $this->calculate('weight_for_age', $sex, $ageDays, $weight, -6, 5),
            'length_height_for_age' => $this->lengthHeightForAge($sex, $ageDays, $height, $position, $snapshot),
            'head_circumference_for_age' => $headCircumference === null
                ? $this->unavailable('measurement_missing')
                : $this->calculate('head_circumference_for_age', $sex, $ageDays, $headCircumference, -5, 5),
        ];

        $snapshot['indicators'] = $indicators;
        $snapshot['status'] = collect($indicators)->contains(fn (array $indicator) => $indicator['status'] === 'calculated')
            ? 'calculated'
            : 'not_calculable';
        $snapshot['reason'] = $snapshot['status'] === 'calculated' ? null : 'measurement_missing';

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function lengthHeightForAge(
        string $sex,
        int $ageDays,
        ?float $height,
        ?string $position,
        array &$snapshot,
    ): array {
        if ($height === null) {
            return $this->unavailable('measurement_missing');
        }
        if ($position === null) {
            return $this->unavailable('measurement_position_missing');
        }

        $expectedRecumbent = $ageDays < 731;
        $adjusted = $height;
        if ($expectedRecumbent && $position === 'standing_height') {
            $adjusted += 0.7;
        }
        if (! $expectedRecumbent && $position === 'recumbent_length') {
            $adjusted -= 0.7;
        }
        $snapshot['length_height_adjusted_cm'] = round($adjusted, 2);

        if (($expectedRecumbent && ($adjusted < 45 || $adjusted > 110))
            || (! $expectedRecumbent && ($adjusted < 65 || $adjusted > 120))) {
            return $this->unavailable('measurement_outside_standard');
        }

        return $this->calculate('length_height_for_age', $sex, $ageDays, $adjusted, -6, 6);
    }

    private function globalReason(Patient $patient, RecordVital $vital): ?string
    {
        if ($patient->birth_date === null || $vital->recorded_at === null) {
            return 'birth_date_missing';
        }
        if (! in_array($patient->gender, ['female', 'male'], true)) {
            return 'sex_not_supported';
        }

        $measurementDate = CarbonImmutable::parse($vital->recorded_at)->startOfDay();
        $birthDate = CarbonImmutable::parse($patient->birth_date)->startOfDay();
        if ($measurementDate->lessThan($birthDate)) {
            return 'measurement_before_birth';
        }
        if ($birthDate->diffInDays($measurementDate) > self::MAX_AGE_DAYS) {
            return 'age_out_of_range';
        }

        return null;
    }

    /** @return array<string, float|string|null> */
    private function calculate(string $indicator, string $sex, int $ageDays, float $measurement, int $lowerLimit, int $upperLimit): array
    {
        if ($measurement <= 0) {
            return $this->unavailable('measurement_outside_standard');
        }

        $row = $this->table($indicator, $sex)[$ageDays] ?? null;
        if ($row === null) {
            return $this->unavailable('age_out_of_range');
        }

        $zScore = $this->zScore($measurement, $row);
        if ($zScore < $lowerLimit || $zScore > $upperLimit) {
            return $this->unavailable('measurement_outside_standard');
        }

        return [
            'status' => 'calculated',
            'reason_code' => null,
            'z_score' => number_format($zScore, 2, '.', ''),
            // WHO presents percentiles outside ±3 SD as NA. Retain the z-score
            // so a professional can review the measurement without inventing a percentile.
            'percentile' => abs($zScore) > 3 ? null : number_format($this->normalCdf($zScore) * 100, 1, '.', ''),
        ];
    }

    /** @return array<string, float|string|null> */
    private function unavailable(string $reason): array
    {
        return [
            'status' => 'not_calculable',
            'reason_code' => $reason,
            'z_score' => null,
            'percentile' => null,
        ];
    }

    /** @return array<string, array<string, float|string|null>> */
    private function unavailableIndicators(string $reason): array
    {
        return array_fill_keys(array_keys(self::TABLES), $this->unavailable($reason));
    }

    /** @param array<string, float> $row */
    private function zScore(float $measurement, array $row): float
    {
        $l = $row['l'];
        $m = $row['m'];
        $s = $row['s'];
        $z = abs($l) < 0.0000001
            ? log($measurement / $m) / $s
            : (pow($measurement / $m, $l) - 1) / ($l * $s);

        if ($z > 3) {
            return 3 + (($measurement - $row['sd3pos']) / ($row['sd3pos'] - $row['sd2pos']));
        }
        if ($z < -3) {
            return -3 + (($measurement - $row['sd3neg']) / ($row['sd2neg'] - $row['sd3neg']));
        }

        return $z;
    }

    private function normalCdf(float $value): float
    {
        $sign = $value < 0 ? -1 : 1;
        $x = abs($value) / sqrt(2);
        $t = 1 / (1 + 0.3275911 * $x);
        $erf = 1 - (((((1.061405429 * $t - 1.453152027) * $t + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t) * exp(-$x * $x);

        return 0.5 * (1 + $sign * $erf);
    }

    /** @return array<int, array<string, float>> */
    private function table(string $indicator, string $sex): array
    {
        $cacheKey = "{$indicator}:{$sex}";
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $filename = self::TABLES[$indicator][$sex] ?? null;
        if ($filename === null) {
            throw new RuntimeException('WHO table is unavailable for this indicator and sex.');
        }
        $path = resource_path("clinical/who-2006/{$filename}");
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Unable to read WHO reference table {$filename}.");
        }
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException("WHO reference table {$filename} has no worksheet.");
        }

        $sheet = simplexml_load_string($xml);
        if ($sheet === false) {
            throw new RuntimeException("WHO reference table {$filename} cannot be parsed.");
        }
        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = $sheet->xpath('//x:sheetData/x:row') ?: [];
        $table = [];
        foreach (array_slice($rows, 1) as $xmlRow) {
            $values = [];
            foreach ($xmlRow->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                $reference = (string) $cell->attributes()->r;
                $column = preg_replace('/\d+/', '', $reference);
                $values[$column] = (float) $cell->v;
            }
            $day = (int) ($values['A'] ?? -1);
            if ($day < 0) {
                continue;
            }
            $table[$day] = [
                'l' => $values['B'], 'm' => $values['C'], 's' => $values['D'],
                'sd3neg' => $values['F'], 'sd2neg' => $values['G'],
                'sd2pos' => $values['K'], 'sd3pos' => $values['L'],
            ];
        }

        return self::$cache[$cacheKey] = $table;
    }

    /** @return array<string, string> */
    private function checksums(): array
    {
        $checksums = [];
        foreach (self::TABLES as $indicator => $bySex) {
            foreach ($bySex as $sex => $filename) {
                $checksums["{$indicator}:{$sex}"] = hash_file('sha256', resource_path("clinical/who-2006/{$filename}"));
            }
        }

        return $checksums;
    }
}
