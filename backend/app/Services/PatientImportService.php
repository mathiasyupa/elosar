<?php

namespace App\Services;

use App\Support\BirthDate;
use App\Models\Patient;
use App\Support\DocumentValidator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bulk import patients from external files with relaxed validation.
 *
 * This service is intentionally separate from the normal patient creation flow:
 * imported records may arrive with incomplete contact data (no email, no address)
 * and foreign identity documents, which the standard FormRequest rejects.
 */
class PatientImportService
{
    /**
     * Import a list of patient rows.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{committed: bool, ready: bool, results: array<int, array{status: string, message: string, patient_id: int|null, line: int}>}
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $preparedRows = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = (int) ($row['source_line'] ?? $index + 2);
            $prepared = $this->prepareRow($row, $line);

            if ($prepared['status'] === 'error') {
                $errors[] = $prepared;
            } else {
                $preparedRows[] = $prepared;
            }
        }

        $seenDocuments = [];

        foreach ($preparedRows as $preparedRow) {
            $key = implode('|', [
                $preparedRow['document_type'],
                $preparedRow['document_country'],
                $preparedRow['id_number'],
            ]);

            if (isset($seenDocuments[$key])) {
                $errors[] = $this->error($preparedRow['line'], 'Documento duplicado dentro del archivo.');
            }

            $seenDocuments[$key] = true;
        }

        if ($errors !== []) {
            return [
                'committed' => false,
                'ready' => false,
                'results' => $errors,
            ];
        }

        if ($dryRun) {
            return [
                'committed' => false,
                'ready' => true,
                'results' => [],
            ];
        }

        try {
            $results = DB::transaction(function () use ($preparedRows): array {
                return array_map(fn (array $row) => $this->createPreparedRow($row), $preparedRows);
            });
        } catch (\Throwable $e) {
            return [
                'committed' => false,
                'ready' => false,
                'results' => [$this->error(0, 'No se pudo guardar el lote completo. Ningún paciente fue creado.')],
            ];
        }

        return [
            'committed' => true,
            'ready' => true,
            'results' => $results,
        ];
    }

    private function prepareRow(array $row, int $line): array
    {
        $name = $this->normalizeName($row['name'] ?? '');
        $idNumber = trim((string) ($row['id_number'] ?? ''));
        $documentType = trim((string) ($row['document_type'] ?? '')) ?: null;
        $documentCountry = isset($row['document_country'])
            ? mb_strtoupper(trim((string) $row['document_country'])) ?: null
            : null;
        $birthDate = $row['birth_date'] ?? null;
        $gender = $this->normalizeGender($row['gender'] ?? null);
        $address = $this->normalizeAddress($row['address'] ?? null);
        $email = $this->normalizeEmail($row['email'] ?? null);
        $phone = $this->normalizePhone($row['phone'] ?? null);

        if ($name === '') {
            return $this->error($line, 'El nombre es obligatorio.');
        }

        if ($idNumber === '') {
            return $this->error($line, 'El número de documento es obligatorio.');
        }

        if ($documentType === null || $documentCountry === null) {
            $suggestion = DocumentValidator::suggest($idNumber);
            $documentType ??= $suggestion['type'];
            $documentCountry ??= $suggestion['country'] ?? 'OT';
        }

        $validation = DocumentValidator::validate($documentType, $documentCountry, $idNumber);
        if ($validation !== null) {
            return $this->error($line, $validation);
        }

        if ($birthDate === null || $birthDate === '') {
            return $this->error($line, 'La fecha de nacimiento es obligatoria.');
        }

        try {
            $parsedBirthDate = Carbon::createFromFormat('!Y-m-d', (string) $birthDate);
            $dateErrors = Carbon::getLastErrors();

            if ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
                return $this->error($line, 'La fecha de nacimiento no es válida.');
            }
        } catch (\Exception) {
            return $this->error($line, 'La fecha de nacimiento no es válida.');
        }

        if ($problem = BirthDate::problem($parsedBirthDate)) {
            return $this->error($line, $problem);
        }

        // The duplicate check deliberately keeps WorkspaceScope, so a
        // personal practice and a clinic may hold independent records for the
        // same real-world person.
        $existing = Patient::query()
            ->withTrashed()
            ->where('id_number', $idNumber)
            ->where('document_type', $documentType)
            ->where('document_country', $documentCountry)
            ->first();

        if ($existing !== null) {
            return $this->error(
                $line,
                $existing->trashed()
                    ? 'Este documento pertenece a un paciente archivado en el espacio de trabajo activo. Restáuralo antes de importarlo.'
                    : 'Ya existe un paciente con este documento en el espacio de trabajo activo.',
            );
        }

        return [
            'status' => 'ready',
            'line' => $line,
            'name' => $name,
            'id_number' => $idNumber,
            'document_type' => $documentType,
            'document_country' => $documentCountry,
            'birth_date' => $parsedBirthDate->toDateString(),
            'gender' => $gender,
            'address' => $address,
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: string, message: string, patient_id: int, line: int}
     */
    private function createPreparedRow(array $row): array
    {
        $patient = Patient::create([
            'name' => $row['name'],
            'id_number' => $row['id_number'],
            'document_type' => $row['document_type'],
            'document_country' => $row['document_country'],
            'birth_date' => $row['birth_date'],
            'gender' => $row['gender'],
            'address' => $row['address'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            // Imported patients do not carry valid LOPDP consent evidence.
            'data_consent_at' => null,
        ]);

        return [
            'status' => 'created',
            'message' => 'Paciente importado.',
            'patient_id' => $patient->id,
            'line' => $row['line'],
        ];
    }

    private function error(int $line, string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'patient_id' => null,
            'line' => $line,
        ];
    }

    private function normalizeName(string $name): string
    {
        $decoded = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = preg_replace('/\s+/', ' ', trim($decoded));

        return mb_convert_case($cleaned, MB_CASE_TITLE, 'UTF-8');
    }

    private function normalizeGender(?string $gender): ?string
    {
        $value = mb_strtolower(trim((string) $gender));

        return match ($value) {
            'masculino', 'male', 'm' => 'male',
            'femenino', 'female', 'f' => 'female',
            'otro', 'other', 'o' => 'other',
            default => null,
        };
    }

    private function normalizeAddress(?string $address): ?string
    {
        $value = html_entity_decode(trim((string) $address), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' ? null : $value;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $value = html_entity_decode(trim((string) $email), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (str_contains($value, 'notiene') || str_contains($value, 'sincorreo')) {
            return null;
        }

        return mb_strtolower($value);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $phone);

        return $value === '' ? null : $value;
    }
}
