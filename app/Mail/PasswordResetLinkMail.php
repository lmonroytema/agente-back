<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $appName,
        public string $recipientName,
        public string $resetUrl,
        public int $expiresMinutes,
        public string $supportEmail,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->appName.' | Restablecimiento seguro de contraseña',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-link',
        );
    }
}
