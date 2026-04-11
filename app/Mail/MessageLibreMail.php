<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class MessageLibreMail extends Mailable
{
    public readonly string $corpsHtml;

    /** @param array<int, string> $attachmentPaths */
    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomParticipant,
        public readonly string $operationNom,
        public readonly string $typeOperationNom,
        public readonly string $dateDebut,
        public readonly string $dateFin,
        public readonly int $nbSeances,
        public readonly ?string $dateProchainSeance,
        public readonly ?string $datePrecedenteSeance,
        public readonly ?int $numeroProchainSeance,
        public readonly ?int $numeroPrecedenteSeance,
        public readonly string $objet,
        public readonly string $corps,
        public readonly array $attachmentPaths = [],
        public readonly ?int $typeOperationId = null,
    ) {
        $allVars = $this->variables() + EmailLogo::variables($this->typeOperationId);
        $corps = str_replace(
            array_keys($allVars),
            array_values($allVars),
            strip_tags($this->corps, EmailLogo::ALLOWED_TAGS)
        );
        $this->corpsHtml = ArticleFr::contracter($corps);
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
        return array_map(
            static fn (string $path) => Attachment::fromPath($path),
            $this->attachmentPaths
        );
    }

    /**
     * Returns variable placeholders present in $corps that resolve to empty string.
     *
     * @param  array<string, string>  $variables
     * @return array<int, string>
     */
    public static function unresolvedVariables(string $corps, array $variables): array
    {
        $unresolved = [];
        foreach ($variables as $placeholder => $value) {
            if ($value === '' && str_contains($corps, $placeholder)) {
                $unresolved[] = $placeholder;
            }
        }

        return $unresolved;
    }

    /** @return array<string, string> */
    private function variables(): array
    {
        return [
            '{prenom}' => $this->prenomParticipant,
            '{nom}' => $this->nomParticipant,
            '{operation}' => $this->operationNom,
            '{type_operation}' => $this->typeOperationNom,
            '{date_debut}' => $this->dateDebut,
            '{date_fin}' => $this->dateFin,
            '{nb_seances}' => (string) $this->nbSeances,
            '{date_prochaine_seance}' => $this->dateProchainSeance ?? '',
            '{date_precedente_seance}' => $this->datePrecedenteSeance ?? '',
            '{numero_prochaine_seance}' => $this->numeroProchainSeance !== null ? (string) $this->numeroProchainSeance : '',
            '{numero_precedente_seance}' => $this->numeroPrecedenteSeance !== null ? (string) $this->numeroPrecedenteSeance : '',
        ];
    }
}
