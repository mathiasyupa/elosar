<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Appointment confirmation, sent to the patient right after a visit is
 * scheduled (the doctor triggers it from the booking confirmation screen).
 * Spanish copy (patient-facing, Ecuador market); replies reach the doctor.
 */
class AppointmentConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Appointment $appointment,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appointment = $this->appointment;
        $date        = $appointment->appointment_date->format('d/m/Y');
        $time        = $appointment->appointment_date->format('H:i');
        $doctor      = $appointment->doctor?->name ?? 'su médico';
        $workspace   = $appointment->workspace?->name
                    ?? $appointment->doctor?->workspace?->name
                    ?? 'la clínica';

        $mail = (new MailMessage)
            ->subject("Confirmación de cita médica — {$date}")
            ->greeting("Hola, {$notifiable->name}")
            ->line("Su **cita médica ha quedado agendada**. Estos son los detalles:")
            ->line("**Fecha:** {$date}")
            ->line("**Hora:** {$time}")
            ->line("**Médico:** {$doctor}")
            ->line("Por favor, preséntese con 10 minutos de anticipación.")
            ->line("Si necesita reprogramar o cancelar, comuníquese con nosotros.")
            ->salutation("Atentamente,\n{$workspace}");

        // Names already include their title (e.g. "Dr. House"); replies reach the doctor.
        if ($appointment->doctor?->email) {
            $mail->replyTo($appointment->doctor->email, $doctor);
        }

        return $mail;
    }
}
