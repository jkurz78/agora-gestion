<?php

declare(strict_types=1);

namespace App\Mail;

use App\Helpers\ArticleFr;
use App\Helpers\EmailLogo;
use App\Models\Seance;
use App\Support\CurrentAssociation;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

final class MessageLibreMail extends Mailable
{
    public readonly string $corpsHtml;

    /** @param array<int, array{path: string, nom: string}|string> $attachmentPaths */
    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomParticipant,
        public readonly string $emailParticipant,
        public readonly string $operationNom,
        public readonly string $typeOperationNom,
        public readonly ?string $libelleArticle,
        public readonly string $dateDebut,
        public readonly string $dateFin,
        public readonly int $nbSeances,
        public readonly ?string $dateProchainSeance,
        public readonly ?string $datePrecedenteSeance,
        public readonly ?int $numeroProchainSeance,
        public readonly ?int $numeroPrecedenteSeance,
        public readonly ?string $titreProchainSeance,
        public readonly ?string $titrePrecedenteSeance,
        public readonly ?int $joursAvantProchaineSeance,
        public readonly int $nbSeancesEffectuees,
        public readonly int $nbSeancesRestantes,
        public readonly string $objet,
        public readonly string $corps,
        public readonly array $attachmentPaths = [],
        public readonly ?int $typeOperationId = null,
        public readonly ?string $trackingToken = null,
        /** @var Collection<int, Seance> */
        public readonly ?Collection $seances = null,
    ) {
        $allVars = $this->variables() + EmailLogo::variables($this->typeOperationId);
        $corps = str_replace(
            array_keys($allVars),
            array_values($allVars),
            strip_tags($this->corps, EmailLogo::ALLOWED_TAGS)
        );
        $html = ArticleFr::contracter($corps);

        // Append tracking pixel if token provided
        if ($this->trackingToken) {
            $pixelUrl = route('email.tracking', ['token' => $this->trackingToken]);
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
        return array_map(
            static function (array|string $item): Attachment {
                if (is_array($item)) {
                    return Attachment::fromPath($item['path'])->as($item['nom']);
                }

                return Attachment::fromPath($item);
            },
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
            '{email_participant}' => $this->emailParticipant,
            '{operation}' => $this->operationNom,
            '{type_operation}' => $this->libelleArticle ?? $this->typeOperationNom,
            '{date_debut}' => $this->dateDebut,
            '{date_fin}' => $this->dateFin,
            '{nb_seances}' => (string) $this->nbSeances,
            '{date_prochaine_seance}' => $this->dateProchainSeance ?? '',
            '{numero_prochaine_seance}' => $this->numeroProchainSeance !== null ? (string) $this->numeroProchainSeance : '',
            '{titre_prochaine_seance}' => $this->titreProchainSeance ?? '',
            '{jours_avant_prochaine_seance}' => $this->joursAvantProchaineSeance !== null ? (string) $this->joursAvantProchaineSeance : '',
            '{date_precedente_seance}' => $this->datePrecedenteSeance ?? '',
            '{numero_precedente_seance}' => $this->numeroPrecedenteSeance !== null ? (string) $this->numeroPrecedenteSeance : '',
            '{titre_precedente_seance}' => $this->titrePrecedenteSeance ?? '',
            '{nb_seances_effectuees}' => (string) $this->nbSeancesEffectuees,
            '{nb_seances_restantes}' => (string) $this->nbSeancesRestantes,
            '{association}' => CurrentAssociation::tryGet()?->nom ?? '',
            '{table_seances}' => $this->buildTableSeances(false),
            '{table_seances_a_venir}' => $this->buildTableSeances(true),
        ];
    }

    private function buildTableSeances(bool $aVenirOnly): string
    {
        if (! $this->seances || $this->seances->isEmpty()) {
            return '';
        }

        $today = now()->startOfDay();
        $filtered = $aVenirOnly
            ? $this->seances->filter(fn (Seance $s) => $s->date && $s->date->gte($today))
            : $this->seances;

        if ($filtered->isEmpty()) {
            return '';
        }

        $rows = '';
        foreach ($filtered->sortBy('date') as $s) {
            $rows .= '<tr>'
                .'<td style="padding:6px 10px;border:1px solid #ddd;text-align:center">'.$s->numero.'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.$s->date?->format('d/m/Y').'</td>'
                .'<td style="padding:6px 10px;border:1px solid #ddd">'.e($s->titre_affiche).'</td>'
                .'</tr>';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:8px 0;font-size:13px">'
            .'<tr style="background:#3d5473;color:#fff">'
            .'<th style="padding:6px 10px;text-align:center;width:50px">N°</th>'
            .'<th style="padding:6px 10px;width:100px">Date</th>'
            .'<th style="padding:6px 10px">Titre</th>'
            .'</tr>'
            .$rows
            .'</table>';
    }
}
