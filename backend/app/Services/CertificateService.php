<?php

namespace App\Services;

use App\Models\MedicalCertificate;
use App\Support\LetterheadData;
use App\Support\SpanishNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Carbon;

/**
 * Generates medical certificate PDFs using the workspace letterhead and the
 * authoring doctor's signature image.
 *
 * Two layouts:
 *  - `medical` → the parameterised certificate an employer or the IESS expects
 *    (contingencia, empresa, actividad, síntomas, diagnóstico + CIE-10,
 *    tratamiento, reposo desde/hasta), with every date in numbers AND letters.
 *  - the rest → the free-text certificate (constancia, reposo, aptitud).
 */
class CertificateService
{
    /** @param  array<string, mixed>  $extra  Extra view data (the Settings preview). */
    public function pdf(MedicalCertificate $certificate, array $extra = []): DomPDF
    {
        $certificate->loadMissing([
            'patient',
            'doctor.specialty',
            'doctor.specialties',
            'workspace',
            'medicalRecord',
        ]);

        $letterhead = LetterheadData::for($certificate->workspace, $certificate->doctor, 'certificate');

        $data = array_merge($letterhead, [
            'certificate' => $certificate,
            'patient'     => $certificate->patient,
            'date'        => $certificate->issued_at,
            'professionalTitle' => $certificate->medicalRecord?->created_by === $certificate->created_by
                ? ($certificate->medicalRecord?->professional_title ?: $certificate->doctor?->primarySpecialty()?->name)
                : $certificate->doctor?->primarySpecialty()?->name,
        ], $extra);

        if ($certificate->isParameterised()) {
            return Pdf::loadView('certificates.parameterised', array_merge($data, $this->parameterisedData($certificate)))
                ->setPaper('a4');
        }

        return Pdf::loadView('certificates.certificate', $data)->setPaper('a4');
    }

    /**
     * Everything the parameterised template needs that is not a plain column:
     * the dates in words, the patient's age and the gender agreement of
     * "el/la paciente … fue atendido/a".
     *
     * @return array<string, mixed>
     */
    private function parameterisedData(MedicalCertificate $certificate): array
    {
        $patient = $certificate->patient;
        $female = ($patient?->gender ?? null) === 'female';

        $short = fn (?Carbon $date) => $date?->format('d/m/Y');
        $long = fn (?Carbon $date) => $date ? mb_strtoupper(SpanishNumber::dateInWords($date)) : null;

        return [
            'issuedLong' => $certificate->issued_at
                ? mb_strtoupper($certificate->issued_at->locale('es')->translatedFormat('j \d\e F Y'))
                : '',
            'attendedShort' => $short($certificate->attended_at),
            'attendedLong' => $long($certificate->attended_at),
            'restFromShort' => $short($certificate->valid_from),
            'restFromLong' => $long($certificate->valid_from),
            'restUntilShort' => $short($certificate->valid_until),
            'restUntilLong' => $long($certificate->valid_until),
            // «3 (TRES) días»: the number, then the same in words, as a sick note is written.
            'restDays' => $restDays = $certificate->rest_days
                ?? ($certificate->valid_from && $certificate->valid_until
                    ? (int) $certificate->valid_from->copy()->startOfDay()->diffInDays($certificate->valid_until->copy()->startOfDay()) + 1
                    : null),
            'restDaysWords' => $restDays ? mb_strtoupper(SpanishNumber::words($restDays)) : null,
            'patientAge' => $patient?->birth_date
                ? Carbon::parse($patient->birth_date)->age
                : null,
            'patientArticle' => $female ? 'la paciente' : 'el paciente',
            'patientVowel' => $female ? 'a' : 'o',
        ];
    }
}
