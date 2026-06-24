<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\EmailLogo;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class QuestionnaireInvitationMail extends Mailable
{
    public function __construct(
        public readonly string $objet,
        public readonly string $corpsHtml,
        public readonly ?string $trackingToken = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->objet);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.questionnaire-invitation', with: [
            'corpsHtml' => $this->corpsHtml,
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $attachments = [];

        // Guard identique à MessageLibreMail : n'attacher le logo que s'il est
        // référencé dans le corps — évite l'affichage en pièce jointe non référencée.
        if (str_contains($this->corpsHtml, 'cid:'.EmailLogo::CID_ASSO)) {
            $logo = EmailLogo::resolve();
            if ($logo) {
                $attachments[] = Attachment::fromPath($logo['path'])
                    ->as(EmailLogo::CID_ASSO)
                    ->withMime($logo['mime']);
            }
        }

        return $attachments;
    }
}
