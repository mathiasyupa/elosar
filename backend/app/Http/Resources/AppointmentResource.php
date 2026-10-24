<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Appointment
 */
class AppointmentResource extends JsonResource
{
    /** Spanish display labels for the appointment status badge (doctor UI). */
    private const STATUS_LABELS = [
        'scheduled'   => 'Agendada',
        'checked_in'  => 'En sala',
        'vitals_done' => 'Triaje listo',
        'with_doctor' => 'Con el médico',
        'completed'   => 'Completada',
        'cancelled'   => 'Cancelada',
        'no_show'     => 'No se presentó',
    ];

    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            // Wall-clock time of the clinic, no TZ offset: it round-trips
            // exactly as typed regardless of the browser's timezone (an
            // offset here made the calendar shift bookings by -5h).
            'appointment_date' => $this->appointment_date?->format('Y-m-d\TH:i:s'),
            'status'           => $this->status,
            'status_label'     => self::STATUS_LABELS[$this->status] ?? $this->status,
            'type'             => $this->type,
            'medical_service_id' => $this->medical_service_id,
            'service_name'     => $this->whenLoaded('medicalService', fn () => $this->medicalService?->name),
            'channel'          => $this->channel,
            'duration_minutes' => $this->duration_minutes,
            'notes'            => $this->notes,
            'confirmed_at'         => $this->confirmed_at?->toIso8601String(),
            'reminder_sent_at'     => $this->reminder_sent_at?->toIso8601String(),
            'whatsapp_reminded_at' => $this->whatsapp_reminded_at?->toIso8601String(),
            'checked_in_at'        => $this->checked_in_at?->toIso8601String(),
            'doctor_id'        => $this->doctor_id,
            'doctor_name'      => $this->whenLoaded('doctor', fn () => $this->doctor?->name),
            'patient'          => PatientResource::make($this->whenLoaded('patient')),
        ];
    }
}
