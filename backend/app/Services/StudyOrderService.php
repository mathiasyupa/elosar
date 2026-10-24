<?php

namespace App\Services;

use App\Models\StudyOrder;
use App\Support\LetterheadData;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;

/**
 * Generates the lab / imaging order PDF ("solicitud de exámenes") from a
 * StudyOrder, in an MSP-aligned format: workspace letterhead, patient data,
 * clinical justification, the requested studies grouped by category and the
 * prescriber's signature + professional registry.
 *
 * Mirrors PrescriptionService: letterhead from the order's workspace, signature
 * from the issuing doctor, images embedded as base64 for dompdf.
 */
class StudyOrderService
{
    /** @param  array<string, mixed>  $extra  Extra view data (the Settings preview). */
    public function pdf(StudyOrder $order, array $extra = []): DomPDF
    {
        $order->loadMissing([
            'patient', 'doctor.specialty', 'doctor.specialties',
            'workspace', 'items', 'medicalRecord.diagnoses',
        ]);

        $letterhead = LetterheadData::for($order->workspace, $order->doctor, 'order');

        // Group requested studies by their (snapshotted) category for the printout.
        $grouped = $order->items
            ->groupBy(fn ($item) => $item->category ?: 'General');

        $data = array_merge($letterhead, [
            'order'       => $order,
            'patient'     => $order->patient,
            'date'        => $order->created_at ?? now(),
            'grouped'     => $grouped,
            'ageDetailed' => $this->detailedAge($order->patient?->birth_date),
            'acessCode'   => $order->workspace?->acess_establishment_code,
            'professionalTitle' => $order->medicalRecord?->created_by === $order->created_by
                ? ($order->medicalRecord?->professional_title ?: $order->doctor?->primarySpecialty()?->name)
                : $order->doctor?->primarySpecialty()?->name,
        ], $extra);

        return Pdf::loadView('orders.study_order', $data)->setPaper('a4');
    }

    /** "42 año(s) 3 mes(es) 1 día(s)" from a birth date. */
    private function detailedAge(mixed $birthDate): ?string
    {
        if (! $birthDate) {
            return null;
        }

        $diff = $birthDate->diff(now());

        return "{$diff->y} año(s) {$diff->m} mes(es) {$diff->d} día(s)";
    }
}
