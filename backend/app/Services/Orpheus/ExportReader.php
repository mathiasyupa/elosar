<?php

namespace App\Services\Orpheus;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class ExportReader
{
    /** @return list<array{row: int, values: array<string, string>}> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Required Orpheus export is missing or unreadable.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Cannot read Orpheus export.');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            // The legacy export mixes UTF-8 and Windows-1252 bytes in one file.
            $raw = preg_replace_callback(
                '/[\xC2-\xDF][\x80-\xBF]|[\xE0-\xEF][\x80-\xBF]{2}|[\xF0-\xF4][\x80-\xBF]{3}|[\x80-\xFF]/',
                static fn (array $match): string => mb_check_encoding($match[0], 'UTF-8')
                    ? $match[0] : mb_convert_encoding($match[0], 'UTF-8', 'Windows-1252'),
                $raw,
            );
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$raw, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new DOMXPath($document);
        $table = $xpath->query('//table')->item(0);
        if ($table === null) {
            throw new RuntimeException('Orpheus export has no table.');
        }

        $rows = $xpath->query('./tr|./thead/tr|./tbody/tr', $table);
        $headers = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $cells = $xpath->query('./th|./td', $row);
            if ($cells->length === 0) {
                continue;
            }
            $values = [];
            foreach ($cells as $cell) {
                $values[] = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $cell->textContent) ?? '');
            }
            if ($headers === []) {
                $headers = array_map(self::key(...), $values);

                continue;
            }
            if (count($headers) !== count($values)) {
                throw new RuntimeException('Orpheus export has a malformed row at '.($index + 1).'.');
            }
            $result[] = ['row' => $index + 1, 'values' => array_combine($headers, $values)];
        }

        if ($headers === [] || $result === []) {
            throw new RuntimeException('Orpheus export has no data rows.');
        }

        return $result;
    }

    public static function key(string $header): string
    {
        return mb_strtolower(trim($header));
    }
}
