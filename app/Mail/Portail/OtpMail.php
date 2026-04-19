<?php

declare(strict_types=1);

namespace App\Mail\Portail;

use App\Models\Association;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Association $association,
        public readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre code de connexion — '.$this->association->nom);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portail.otp');
    }
}
