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

    public function __construct(
        public readonly UserInvitation $invitation,
        public readonly string $rawToken,
    ) {}

    public function envelope(): Envelope
    {
        $subject = app(\App\Services\SettingsService::class)->get('email_convite_assunto', 'Convite — Piscinas MMCrespo');
        return new Envelope(
            subject: $subject,
        );
    }

    public function content(): Content
    {
        $mensagem = app(\App\Services\SettingsService::class)->get('email_convite_mensagem', 'Foi convidado(a) para aceder à plataforma de gestão operacional da MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:');
        return new Content(
            view: 'emails.user-invitation',
            with: [
                'url'       => url('/convite/'.$this->rawToken),
                'role'      => $this->invitation->role,
                'email'     => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at->format('d/m/Y \à\s H:i'),
                'mensagem'  => $mensagem,
            ],
        );
    }
}
