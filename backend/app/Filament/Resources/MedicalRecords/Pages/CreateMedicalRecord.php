<?php

namespace App\Filament\Resources\MedicalRecords\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\MedicalRecords\MedicalRecordResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Medical record creation page.
 *
 * When accessed from the "Start consultation" flow (button on the appointments
 * table), the `appointment_id`, `patient_id` and `doctor_id` query params
 * pre-fill the form and, after saving, redirect automatically to invoice creation.
 */
class CreateMedicalRecord extends CreateRecord
{
    protected static string $resource = MedicalRecordResource::class;

    /**
     * Before creating: injects `appointment_id` from the query param
     * (`patient_id` and `created_by` are pre-filled via `->default()` in the form).
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $appointmentId = request()->query('appointment_id');

        if ($appointmentId) {
            $data['appointment_id'] = (int) $appointmentId;
        }

        return $data;
    }

    /**
     * After creating the record:
     * - If it came from an appointment, redirects to invoice creation with the patient pre-filled.
     * - Otherwise, standard behavior (back to list or edit).
     */
    protected function getRedirectUrl(): string
    {
        $appointmentId = request()->query('appointment_id');
        $patientId     = $this->record?->patient_id;

        if ($appointmentId && $patientId) {
            return InvoiceResource::getUrl('create') . '?' . http_build_query([
                'patient_id'     => $patientId,
                'appointment_id' => $appointmentId,
            ]);
        }

        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
