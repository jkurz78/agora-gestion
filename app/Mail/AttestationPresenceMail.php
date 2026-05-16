<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use App\Mail\Concerns\HasPolitesseVariables;
use App\Support\CurrentAssociation;
use App\Support\TemplateSubstitution;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AttestationPresenceMail extends Mailable
{
    use HasPolitesseVariables;
    use Queueable;
    use SerializesModels;

    public readonly string $corpsHtml;

    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomParticipant,
        public readonly string $nomOperation,
        public readonly string $nomTypeOperation,
        public readonly string $dateDebut,
        public readonly string $dateFin,
        public readonly string $nombreSeances,
        public readonly ?string $numeroSeance,
        public readonly ?string $dateSeance,
        public readonly ?string $customObjet,
        public readonly ?string $customCorps,
        public readonly string $pdfContent,
        public readonly string $pdfFilename,
        public readonly ?string $libelleArticle = null,
        public readonly ?string $blocSeances = null,
        public readonly ?int $typeOperationId = null,
        public readonly ?string $civilite = null,
        public readonly ?string $politesse = null,
    ) {
        $corps = $this->customCorps ?? '<p>Bonjour {prenom}, veuillez trouver ci-joint votre attestation de présence.</p>';
        $allVars = $this->variables() + EmailLogo::variables($this->typeOperationId);
        $corps = TemplateSubstitution::apply(
            strip_tags($corps, EmailLogo::ALLOWED_TAGS),
            $allVars
        );
        $this->corpsHtml = ArticleFr::contracter($corps);
    }

    public function envelope(): Envelope
    {
        $objet = $this->customObjet ?? 'Attestation de présence — {operation}';
        $subject = TemplateSubstitution::apply($objet, $this->variables());

        return new Envelope(subject: ArticleFr::contracter($subject));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.attestation-presence',
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $attachments = [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];

        // Only attach the association logo if the body actually references it.
        // Without this guard, clients (Gmail, Apple Mail) display unreferenced
        // inline attachments at the bottom of the message at native size
        // — producing the "giant logo" footer regression.
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

    /**
     * @return array<string, string>
     */
    private function variables(): array
    {
        return [
            '{prenom}' => $this->prenomParticipant,
            '{nom}' => $this->nomParticipant,
            '{operation}' => $this->nomOperation,
            '{type_operation}' => $this->libelleArticle ?? $this->nomTypeOperation,
            '{date_debut}' => $this->dateDebut,
            '{date_fin}' => $this->dateFin,
            '{nb_seances}' => $this->nombreSeances,
            '{numero_seance}' => $this->numeroSeance ?? '',
            '{date_seance}' => $this->dateSeance ?? '',
            '{bloc_seances}' => $this->blocSeances ?? '',
            '{association}' => CurrentAssociation::tryGet()?->nom ?? '',
        ] + $this->politesseVariables(
            $this->civilite,
            $this->politesse,
            $this->prenomParticipant,
            $this->nomParticipant,
        );
    }
}
