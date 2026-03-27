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

    public readonly string $corpsHtml;

    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomParticipant,
        public readonly string $nomOperation,
        public readonly string $formulaireUrl,
        public readonly string $tokenCode,
        public readonly string $dateExpiration,
        public readonly string $dateDebut = '',
        public readonly string $dateFin = '',
        public readonly string $nombreSeances = '',
        public readonly ?string $customObjet = null,
        public readonly ?string $customCorps = null,
    ) {
        $vars = $this->variables();

        $corps = $this->customCorps
            ?? "Bonjour {prenom},\n\nNous vous invitons à compléter votre formulaire pour {operation}.";

        $corps = str_replace(array_keys($vars), array_values($vars), $corps);

        $this->corpsHtml = nl2br(e($corps));
    }

    public function envelope(): Envelope
    {
        $vars = $this->variables();

        $objet = $this->customObjet
            ?? 'Formulaire à compléter — {operation}';

        $objet = str_replace(array_keys($vars), array_values($vars), $objet);

        return new Envelope(subject: $objet);
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
            '{date_debut}' => $this->dateDebut,
            '{date_fin}' => $this->dateFin,
            '{nb_seances}' => $this->nombreSeances,
        ];
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.formulaire-invitation',
        );
    }
}
