<?php

namespace App\Notifications;

use App\Models\Doctor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Patient relationship greeting for the "Felicita a tu paciente" section:
 * birthday, patient anniversary, welcome or re-engagement. Spanish copy;
 * replies reach the doctor who sent it. Mirrors the WhatsApp templates in
 * medico-web/src/lib/whatsapp.ts.
 */
class PatientGreetingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPES = ['birthday', 'anniversary', 'welcome', 'reactivation'];

    public function __construct(
        public readonly string $type,
        public readonly ?Doctor $doctor = null,
        public readonly ?int $years = null,
        public readonly ?string $workspaceName = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name      = $notifiable->name;
        $workspace = $this->workspaceName ?? $this->doctor?->workspace?->name ?? 'la clínica';
        $years     = $this->years ?? 1;

        [$subject, $lines] = match ($this->type) {
            'anniversary' => [
                '¡Gracias por confiar en nosotros!',
                ["¡Hola, {$name}!", "Hoy se cumple{$this->plural($years)} **{$years} año".($years === 1 ? '' : 's')."** desde su primera consulta con nosotros.", 'Gracias por confiar en nuestro cuidado todo este tiempo. 🎉'],
            ],
            'welcome' => [
                '¡Bienvenido/a!',
                ["¡Hola, {$name}!", 'Gracias por elegirnos para su atención médica. 👋', 'Quedamos atentos para acompañarle en su salud.'],
            ],
            'reactivation' => [
                'Le extrañamos — agende su control',
                ["Hola, {$name}.", 'Notamos que ha pasado un tiempo desde su última visita.', '¿Le gustaría agendar un control? Estamos para ayudarle. 😊'],
            ],
            default => [ // birthday
                '¡Feliz cumpleaños! 🎉',
                ["¡Feliz cumpleaños, {$name}! 🎂", 'De parte de todo el equipo le deseamos un día maravilloso y mucha salud.', 'Gracias por su confianza.'],
            ],
        };

        $mail = (new MailMessage)->subject($subject);
        foreach ($lines as $line) {
            $mail->line($line);
        }
        $mail->salutation("Atentamente,\n{$workspace}");

        // The doctor name already includes its title (e.g. "Dr. House").
        if ($this->doctor?->email) {
            $mail->replyTo($this->doctor->email, $this->doctor->name);
        }

        return $mail;
    }

    private function plural(int $years): string
    {
        return $years === 1 ? '' : 'n';
    }
}
