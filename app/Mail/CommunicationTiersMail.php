<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use App\Support\CurrentAssociation;
use App\Support\TenantUrl;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class CommunicationTiersMail extends Mailable
{
    public readonly string $corpsHtml;

    /** @param array<int, array{path: string, nom: string}|string> $attachmentPaths */
    public function __construct(
        public readonly string $prenom,
        public readonly string $nom,
        public readonly string $email,
        public readonly string $objet,
        public readonly string $corps,
        public readonly ?string $trackingToken = null,
        public readonly array $attachmentPaths = [],
    ) {
        $allVars = $this->variables() + EmailLogo::variables();
        $corps = str_replace(
            array_keys($allVars),
            array_values($allVars),
            strip_tags($this->corps, EmailLogo::ALLOWED_TAGS)
        );
        $html = ArticleFr::contracter($corps);

        if ($this->trackingToken) {
            // Opt-out footer: only if user didn't already include an opt-out link
            $hasOptoutInBody = str_contains($this->corps, '{lien_optout}')
                || str_contains($this->corps, '{lien_desinscription}');

            if (! $hasOptoutInBody) {
                $optoutUrl = TenantUrl::route('email.optout', ['token' => $this->trackingToken]);
                $html .= '<p style="font-size:11px;color:#999;margin-top:20px;text-align:center">'
                    .'<a href="'.htmlspecialchars($optoutUrl).'" style="color:#999">Se désinscrire des communications</a>'
                    .'</p>';
            }

            // Tracking pixel
            $pixelUrl = TenantUrl::route('email.tracking', ['token' => $this->trackingToken]);
            $html .= '<img src="'.htmlspecialchars($pixelUrl).'" width="1" height="1" alt="" style="display:none">';
        }

        $this->corpsHtml = $html;
    }

    public function envelope(): Envelope
    {
        $subject = str_replace(
            array_keys($this->variables()),
            array_values($this->variables()),
            $this->objet
        );

        return new Envelope(subject: ArticleFr::contracter($subject));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.message');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $attachments = array_map(
            static function (array|string $item): Attachment {
                if (is_array($item)) {
                    return Attachment::fromPath($item['path'])->as($item['nom']);
                }

                return Attachment::fromPath($item);
            },
            $this->attachmentPaths
        );

        $logo = EmailLogo::resolve();
        if ($logo) {
            $attachments[] = Attachment::fromPath($logo['path'])
                ->as(EmailLogo::CID_ASSO)
                ->withMime($logo['mime']);
        }

        return $attachments;
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        $optoutUrl = $this->trackingToken
            ? TenantUrl::route('email.optout', ['token' => $this->trackingToken])
            : '#';

        return [
            '{prenom}' => $this->prenom,
            '{nom}' => $this->nom,
            '{email}' => $this->email,
            '{association}' => CurrentAssociation::tryGet()?->nom ?? '',
            '{lien_optout}' => $optoutUrl,
            '{lien_desinscription}' => '<a href="'.htmlspecialchars($optoutUrl).'" style="color:#999">Se désinscrire</a>',
        ];
    }
}
