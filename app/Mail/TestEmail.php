<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class TestEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $typeNom,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Test email — {$this->typeNom}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Ceci est un mail de test envoyé depuis <strong>SVS Accounting</strong> pour le type d'opération <strong>{$this->typeNom}</strong>.</p><p>Si vous recevez ce message, la configuration email est fonctionnelle.</p>",
        );
    }
}
