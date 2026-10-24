<?php

namespace App\Console\Commands;

use App\Models\Cie10Code;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports the full CIE-10 (ICD-10) catalog from a tabular CSV into cie10_codes.
 *
 * PAHO/WHO does not publish a single import-ready CSV; the practical Spanish
 * source for Latin America is the Mexican DGIS / datos.gob.mx CIE-10 catalog
 * (tabular, downloadable, ISO-8859-1, ';'-delimited). This command is format-
 * tolerant: it auto-detects the delimiter, encoding and the code/description
 * columns, derives a CIE-10 chapter as the browse category, and UPSERTS by code
 * so it is safe to re-run and to layer on top of the curated seed.
 *
 * Examples:
 *   php artisan cie10:import storage/app/cie10.csv --truncate --dotify
 *   php artisan cie10:import file.csv --code-col=CATALOG_KEY --desc-col=NOMBRE
 */
class ImportCie10Command extends Command
{
    protected $signature = 'cie10:import
        {file : Path to the CSV file}
        {--truncate : Empty the catalog before importing (clean full import)}
        {--code-col= : Header name (or 0-based index) of the code column}
        {--desc-col= : Header name (or 0-based index) of the description column}
        {--category-col= : Header name/index of a category column (else derived by chapter)}
        {--delimiter= : Force the CSV delimiter (default: auto , or ;)}
        {--encoding= : Source encoding to convert from (default: auto UTF-8/ISO-8859-1)}
        {--dotify : Insert the conventional dot in 4-char codes (A000 -> A00.0)}';

    protected $description = 'Import the CIE-10 catalog from a CSV (DGIS/datos.gob.mx or compatible).';

    /** Header aliases used to auto-detect columns (normalized: lower, no accents). */
    private const CODE_ALIASES = ['codigo', 'cie', 'cie10', 'clave', 'clave_cie', 'code', 'catalog_key', 'llave'];
    private const DESC_ALIASES = ['nombre', 'descripcion', 'desc', 'nombre_largo', 'description', 'glosa', 'titulo'];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open: {$path}");

            return self::FAILURE;
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            $this->error('Empty file.');
            fclose($handle);

            return self::FAILURE;
        }

        $delimiter = $this->option('delimiter') ?: (substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',');
        $header = array_map(fn ($h) => $this->toUtf8(trim($h)), str_getcsv($firstLine, $delimiter));

        $codeIdx = $this->resolveColumn($this->option('code-col'), $header, self::CODE_ALIASES, 'code');
        $descIdx = $this->resolveColumn($this->option('desc-col'), $header, self::DESC_ALIASES, 'description');
        if ($codeIdx === null || $descIdx === null) {
            $this->error('Could not resolve code/description columns. Header: '.implode(' | ', $header));
            $this->line('Use --code-col= and --desc-col= (header name or 0-based index).');
            fclose($handle);

            return self::FAILURE;
        }
        $categoryIdx = $this->option('category-col') !== null
            ? $this->resolveColumn($this->option('category-col'), $header, [], 'category')
            : null;

        if ($this->option('truncate')) {
            DB::table('cie10_codes')->truncate();
            $this->warn('cie10_codes truncated.');
        }

        $dotify = (bool) $this->option('dotify');
        $now = now();
        $batch = [];
        $seen = [];
        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $code = strtoupper(trim($this->toUtf8($row[$codeIdx] ?? '')));
            $description = $this->collapse($this->toUtf8($row[$descIdx] ?? ''));

            if ($dotify) {
                $code = $this->dotify($code);
            }

            // Keep only real leaf/category codes (letter + 2 digits, optional .x).
            // Drops chapter ranges ("A00-B99"), blanks and header artifacts.
            if ($description === '' || ! preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]+)?$/', $code)) {
                $skipped++;
                continue;
            }
            if (isset($seen[$code])) {
                continue; // de-dupe within the file
            }
            $seen[$code] = true;

            $category = $categoryIdx !== null
                ? ($this->collapse($this->toUtf8($row[$categoryIdx] ?? '')) ?: null)
                : $this->chapterFor($code);

            $batch[] = [
                'code'        => mb_substr($code, 0, 10),
                'description' => mb_substr($description, 0, 255),
                'category'    => $category ? mb_substr($category, 0, 100) : null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
            $imported++;

            if (count($batch) >= 1000) {
                $this->flush($batch);
                $batch = [];
            }
        }

        if ($batch) {
            $this->flush($batch);
        }
        fclose($handle);

        $this->info("CIE-10 import done: {$imported} upserted, {$skipped} skipped. Total now: ".Cie10Code::count());

        return self::SUCCESS;
    }

    /**
     * Upsert a batch by code (insert new, update description/category on conflict).
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function flush(array $batch): void
    {
        Cie10Code::upsert($batch, ['code'], ['description', 'category', 'updated_at']);
    }

    /**
     * Resolve a column index from an explicit option (header name or numeric
     * index) or by matching header aliases. Returns null if unresolved.
     *
     * @param  list<string>  $header
     * @param  list<string>  $aliases
     */
    private function resolveColumn(?string $option, array $header, array $aliases, string $label): ?int
    {
        if ($option !== null && $option !== '') {
            if (ctype_digit($option)) {
                return (int) $option;
            }
            $want = $this->normalize($option);
            foreach ($header as $i => $name) {
                if ($this->normalize($name) === $want) {
                    return $i;
                }
            }
            $this->warn("Column '{$option}' for {$label} not found in header.");

            return null;
        }

        foreach ($header as $i => $name) {
            if (in_array($this->normalize($name), $aliases, true)) {
                return $i;
            }
        }

        return null;
    }

    /** Map a CIE-10 code to its Spanish chapter label (browse category). */
    private function chapterFor(string $code): ?string
    {
        $letter = $code[0] ?? '';
        $num = (int) substr($code, 1, 2);

        return match (true) {
            $letter === 'A' || $letter === 'B' => 'Ciertas enfermedades infecciosas y parasitarias',
            $letter === 'C' || ($letter === 'D' && $num <= 48) => 'Neoplasias',
            $letter === 'D' => 'Enfermedades de la sangre y de los órganos hematopoyéticos',
            $letter === 'E' => 'Enfermedades endocrinas, nutricionales y metabólicas',
            $letter === 'F' => 'Trastornos mentales y del comportamiento',
            $letter === 'G' => 'Enfermedades del sistema nervioso',
            $letter === 'H' && $num <= 59 => 'Enfermedades del ojo y sus anexos',
            $letter === 'H' => 'Enfermedades del oído y de la apófisis mastoides',
            $letter === 'I' => 'Enfermedades del sistema circulatorio',
            $letter === 'J' => 'Enfermedades del sistema respiratorio',
            $letter === 'K' => 'Enfermedades del sistema digestivo',
            $letter === 'L' => 'Enfermedades de la piel y del tejido subcutáneo',
            $letter === 'M' => 'Enfermedades del sistema osteomuscular y del tejido conjuntivo',
            $letter === 'N' => 'Enfermedades del sistema genitourinario',
            $letter === 'O' => 'Embarazo, parto y puerperio',
            $letter === 'P' => 'Afecciones originadas en el período perinatal',
            $letter === 'Q' => 'Malformaciones congénitas, deformidades y anomalías cromosómicas',
            $letter === 'R' => 'Síntomas, signos y hallazgos anormales clínicos y de laboratorio',
            $letter === 'S' || $letter === 'T' => 'Traumatismos, envenenamientos y otras causas externas',
            $letter === 'V' || $letter === 'W' || $letter === 'X' || $letter === 'Y' => 'Causas externas de morbilidad y de mortalidad',
            $letter === 'Z' => 'Factores que influyen en el estado de salud y contacto con servicios de salud',
            $letter === 'U' => 'Códigos para situaciones especiales',
            default => null,
        };
    }

    /** A000 -> A00.0 ; A00 stays A00 ; already-dotted stays unchanged. */
    private function dotify(string $code): string
    {
        if (str_contains($code, '.') || strlen($code) <= 3) {
            return $code;
        }

        return substr($code, 0, 3).'.'.substr($code, 3);
    }

    private function toUtf8(string $value): string
    {
        $encoding = $this->option('encoding');
        if ($encoding) {
            return mb_convert_encoding($value, 'UTF-8', $encoding);
        }
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return $value;
    }
}
