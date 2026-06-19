<?php declare(strict_types=1);

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly UserInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Convite — Piscinas MMCrespo',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-invitation',
            with: [
                'url'       => url('/convite/'.$this->invitation->token),
                'role'      => $this->invitation->role,
                'email'     => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at->format('d/m/Y \à\s H:i'),
            ],
        );
    }
}
