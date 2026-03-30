<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AttestationPresenceMail extends Mailable
{
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
    ) {
        $corps = $this->customCorps ?? '<p>Bonjour {prenom}, veuillez trouver ci-joint votre attestation de présence.</p>';
        $allowedTags = '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div><table><tr><td><th>';
        $corps = str_replace(
            array_keys($this->variables()),
            array_values($this->variables()),
            strip_tags($corps, $allowedTags)
        );
        $this->corpsHtml = ArticleFr::contracter($corps);
    }

    public function envelope(): Envelope
    {
        $objet = $this->customObjet ?? 'Attestation de présence — {operation}';
        $subject = str_replace(
            array_keys($this->variables()),
            array_values($this->variables()),
            $objet
        );

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
        return [
            Attachment::fromData(fn () => $this->pdfContent, $this->pdfFilename)
                ->withMime('application/pdf'),
        ];
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
        ];
    }
}
