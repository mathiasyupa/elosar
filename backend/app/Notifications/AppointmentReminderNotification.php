<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Appointment reminder notification.
 *
 * Sent to the patient the day before their appointment (by email).
 * If the patient has no email, the Job silently skips it.
 * Email copy is intentionally in Spanish (patient-facing, Ecuador market).
 */
class AppointmentReminderNotification extends Notification implements ShouldQueue
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
            ->subject("Recordatorio de cita médica — {$date}")
            ->greeting("Hola, {$notifiable->name}")
            ->line("Le recordamos que tiene una **cita médica programada para mañana**.")
            ->line("**Fecha:** {$date}")
            ->line("**Hora:** {$time}")
            ->line("**Médico:** {$doctor}")
            ->line("Por favor, preséntese con 10 minutos de anticipación.")
            ->line("Si necesita cancelar o reprogramar su cita, comuníquese con nosotros a la brevedad.")
            ->salutation("Atentamente,\n{$workspace}");

        // Replies reach the attending doctor (the email sends from the clinic's
        // verified address, so we can't set From as the doctor without breaking
        // SPF/DKIM — Reply-To is the deliverable way to be "from the doctor").
        // Names are already stored with their title (e.g. "Dr. House").
        if ($appointment->doctor?->email) {
            $mail->replyTo($appointment->doctor->email, $doctor);
        }

        return $mail;
    }
}
