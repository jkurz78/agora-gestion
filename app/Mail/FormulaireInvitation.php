<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FormulaireInvitation extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomOperation,
        public readonly string $formulaireUrl,
        public readonly string $tokenCode,
        public readonly string $dateExpiration,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Formulaire à compléter — {$this->nomOperation}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.formulaire-invitation',
        );
    }
}
