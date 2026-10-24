<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Support\LetterheadData;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;

/**
 * Generates the prescription PDF from a medical record, in an MSP-aligned format
 * (Ecuador): código, validity ("válida hasta"), detailed age, diagnosis (CIE-10),
 * medications by generic name (DCI) + quantity in numbers and letters, dosage,
 * recommendations / alarm signs / allergies, next appointment and the prescriber's
 * MSP registry.
 *
 * The letterhead (logo, header/footer, validity days) comes from the record's
 * WORKSPACE; the signature comes from the authoring doctor.
 */
class PrescriptionService
{
    /**
     * @param  array<string, mixed>  $extra  Extra view data (the Settings preview
     *                                       marks its sample as such).
     */
    public function pdf(MedicalRecord $record, array $extra = []): DomPDF
    {
        $data = array_merge($this->viewData($record), $extra);

        // Two halves to cut (receta | indicaciones) on a landscape A4, or one
        // uncut portrait A4 with the indications below the receta.
        return Pdf::loadView('prescriptions.prescription', $data)
            ->setPaper('a4', $data['portrait'] ? 'portrait' : 'landscape');
    }

    /**
     * The prescription as rendered HTML, before dompdf turns it into a page.
     *
     * Exposed for tests: a layout defect — a blank cell, a field that stopped
     * printing — is invisible in the PDF byte stream, so asserting on the
     * document means asserting on this.
     */
    public function html(MedicalRecord $record): string
    {
        return view('prescriptions.prescription', $this->viewData($record))->render();
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(MedicalRecord $record): array
    {
        $record->loadMissing([
            'patient.allergies', 'doctor.specialty', 'doctor.specialties',
            'workspace', 'diagnoses', 'prescriptions',
        ]);

        $letterhead = LetterheadData::for($record->workspace, $record->doctor, 'prescription');

        $validityDays = (int) ($letterhead['settings']['validity_days'] ?? 3);
        $issuedAt = $record->created_at ?? now();

        // Next scheduled appointment for this patient (inherits the active
        // workspace scope), if any — shows as "Próxima cita".
        $nextAppointment = Appointment::query()
            ->where('patient_id', $record->patient_id)
            ->where('status', 'scheduled')
            ->where('appointment_date', '>', now())
            ->orderBy('appointment_date')
            ->first();

        return array_merge($letterhead, [
            'record'           => $record,
            'patient'          => $record->patient,
            'date'             => $issuedAt,
            'validUntil'       => $issuedAt->copy()->addDays($validityDays),
            'issueCity'        => $letterhead['settings']['issue_city'] ?? null,
            'portrait'         => ($letterhead['settings']['prescription_layout'] ?? 'landscape') === 'portrait',
            'ageDetailed'      => $this->detailedAge($record->patient?->birth_date),
            'nextAppointment'  => $nextAppointment,
            'hasControlled'    => $record->prescriptions->contains(fn ($p) => $p->is_controlled),
            'acessCode'        => $record->workspace?->acess_establishment_code,
            'allergies'        => $record->patient?->allergies
                ?->map(fn ($a) => $a->allergen_name ?? $a->allergen_type)->filter()->implode(', '),
        ]);
    }

    /** "80 año(s) 4 mes(es) 9 día(s)" from a birth date. */
    private function detailedAge(mixed $birthDate): ?string
    {
        if (! $birthDate) {
            return null;
        }

        $diff = $birthDate->diff(now());

        return "{$diff->y} año(s) {$diff->m} mes(es) {$diff->d} día(s)";
    }
}
