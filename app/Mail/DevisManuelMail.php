<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Devis;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DevisManuelMail extends Mailable
{
    public function __construct(
        public readonly Devis $devis,
        public readonly string $sujet,
        public readonly string $corps,
        public readonly string $pdfPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->sujet);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.devis-manuel');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $numero = $this->devis->numero !== null
            ? str_replace('/', '-', $this->devis->numero)
            : 'brouillon-'.$this->devis->id;

        return [
            Attachment::fromStorageDisk('local', $this->pdfPath)
                ->as('devis-'.$numero.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
