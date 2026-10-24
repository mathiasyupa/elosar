<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation to join a clinic as staff (Spanish, patient-market facing). Carries
 * the redeem path with the token so the recipient can register. Sending is
 * best-effort — the owner always also gets the link in the API response.
 *
 * ShouldQueue like every other outbound notification in this codebase: without
 * it, TeamController::invite() blocked its HTTP response on an SMTP round trip.
 */
class ClinicInviteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $clinicName,
        public string $roleLabel,
        public string $redeemUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Invitación para unirte a {$this->clinicName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.clinic_invite',
        );
    }
}
