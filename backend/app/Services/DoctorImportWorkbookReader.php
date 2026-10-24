<?php

namespace App\Services;

use DateTimeInterface;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

final class DoctorImportWorkbookReader
{
    private const HEADERS = [
        'MÉDICO / PROFESIONAL',
        'ESPECIALIDAD',
        'NÚMERO DE CÉDULA',
        'FECHA DE NACIMIENTO',
        'correo',
        'CELULAR',
    ];

    /** @return list<array{row:int, name:string, specialty:string, id_number:string, birth_date:string, email:string, phone:string}> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('The doctor workbook must be a readable .xlsx file.');
        }

        $reader = new Reader;
        $reader->open($path);

        try {
            $sheets = array_values(iterator_to_array($reader->getSheetIterator()));
            if (count($sheets) !== 1) {
                throw new RuntimeException('The doctor workbook must contain exactly one sheet.');
            }

            $rows = [];
            $hasHeader = false;
            foreach ($sheets[0]->getRowIterator() as $index => $row) {
                $cells = $row->toArray();
                if (! $hasHeader) {
                    if ($cells !== self::HEADERS) {
                        throw new RuntimeException('Unexpected doctor workbook headers.');
                    }
                    $hasHeader = true;

                    continue;
                }

                if (count($cells) > 6 && array_filter(array_slice($cells, 6), static fn ($value): bool => $value !== null && $value !== '') !== []) {
                    throw new RuntimeException('Unexpected doctor workbook columns.');
                }

                $cells = array_pad($cells, 6, '');
                if (array_filter(array_slice($cells, 0, 6), static fn ($value): bool => $value !== null && $value !== '') === []) {
                    continue;
                }

                $values = array_map(static fn ($value): string => trim($value instanceof DateTimeInterface
                    ? $value->format('Y-m-d') : (string) $value), array_slice($cells, 0, 6));
                $rows[] = array_combine(['row', 'name', 'specialty', 'id_number', 'birth_date', 'email', 'phone'], [$index, ...$values]);
            }

            if (! $hasHeader) {
                throw new RuntimeException('The doctor workbook has no header.');
            }

            return $rows;
        } finally {
            $reader->close();
        }
    }
}
