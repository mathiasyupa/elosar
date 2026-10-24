<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\MedicalService;
use App\Models\Patient;
use Illuminate\Support\Facades\Auth;

/**
 * Bulk import historical appointments from external files.
 *
 * The service resolves patients by their document tuple within the active
 * workspace, maps the external status to Elosar values, and avoids creating
 * exact duplicates for the same patient + datetime.
 */
class AppointmentImportService
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{status: string, message: string, appointment_id: int|null}>
     */
    public function import(array $rows): array
    {
        $doctorId = Auth::user()?->doctor?->id;
        $results = [];

        foreach ($rows as $index => $row) {
            $results[] = $this->importRow($row, $index + 1, $doctorId);
        }

        return $results;
    }

    private function importRow(array $row, int $line, ?int $doctorId): array
    {
        $date = $row['appointment_date'] ?? null;
        $idNumber = trim((string) ($row['id_number'] ?? ''));
        $documentType = $row['document_type'] ?? 'cedula';
        $documentCountry = $row['document_country'] ?? 'EC';
        $status = $this->normalizeStatus($row['status'] ?? null);
        $notes = $this->normalizeNotes($row['notes'] ?? null);
        $serviceName = $this->normalizeService($row['service_name'] ?? null);
        $channel = $this->normalizeChannel($row['channel'] ?? null);

        if ($date === null || $date === '') {
            return $this->error($line, 'La fecha y hora de la cita son obligatorias.');
        }

        if ($idNumber === '') {
            return $this->error($line, 'La cédula del paciente es obligatoria.');
        }

        $patient = Patient::query()
            ->where('id_number', $idNumber)
            ->where('document_type', $documentType)
            ->where('document_country', $documentCountry)
            ->first();

        if ($patient === null) {
            return $this->error($line, 'No se encontró el paciente con ese documento. Importa primero los pacientes.');
        }

        if ($doctorId === null) {
            return $this->error($line, 'No se pudo determinar el médico para la cita.');
        }

        $existing = Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('appointment_date', $date)
            ->first();

        if ($existing !== null) {
            return $this->error($line, 'Cita duplicada para el mismo paciente y horario.');
        }

        $serviceId = null;
        if ($serviceName !== null) {
            $service = MedicalService::query()
                ->whereRaw('lower(name) = lower(?)', [$serviceName])
                ->first();
            $serviceId = $service?->id;
        }

        try {
            $appointment = Appointment::create([
                'patient_id'         => $patient->id,
                'doctor_id'          => $doctorId,
                'appointment_date'   => $date,
                'status'             => $status,
                'type'               => $channel === 'telemedicine' ? 'telemedicine' : 'first_visit',
                'medical_service_id' => $serviceId,
                'duration_minutes'   => 30,
                'notes'              => $notes,
                'channel'            => $channel,
            ]);

            return [
                'status'         => 'created',
                'message'        => 'Cita importada.',
                'appointment_id' => $appointment->id,
                'line'           => $line,
            ];
        } catch (\Throwable $e) {
            return $this->error($line, 'Error al guardar: '.$e->getMessage());
        }
    }

    private function error(int $line, string $message): array
    {
        return [
            'status'         => 'error',
            'message'        => $message,
            'appointment_id' => null,
            'line'           => $line,
        ];
    }

    private function normalizeStatus(?string $status): string
    {
        $value = mb_strtoupper(trim((string) $status));

        return match ($value) {
            'ATENDIDA' => 'completed',
            'INCUMPLIDA' => 'cancelled',
            default => 'scheduled',
        };
    }

    private function normalizeNotes(?string $notes): ?string
    {
        $value = html_entity_decode(trim((string) $notes), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' ? null : $value;
    }

    private function normalizeService(?string $service): ?string
    {
        $value = html_entity_decode(trim((string) $service), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $value === '' ? null : $value;
    }

    private function normalizeChannel(?string $channel): string
    {
        $value = mb_strtolower(trim((string) $channel));

        if (str_contains($value, 'video') || str_contains($value, 'teleconsulta')) {
            return 'web';
        }

        return 'in_person';
    }
}
