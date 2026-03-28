<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailTemplate;
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

    public readonly bool $showAutoBlock;

    public function __construct(
        public readonly string $prenomParticipant,
        public readonly string $nomParticipant,
        public readonly string $nomOperation,
        public readonly string $nomTypeOperation,
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
            ?? '<p>Bonjour <strong>{prenom}</strong>,</p><p>Nous vous invitons à compléter votre formulaire pour <strong>{operation}</strong>.</p>';

        // If template uses {bloc_liens} or {url}, don't show the auto block
        $this->showAutoBlock = ! str_contains($corps, '{bloc_liens}') && ! str_contains($corps, '{url}');

        $corps = str_replace(array_keys($vars), array_values($vars), $corps);

        // Allow the bloc_liens HTML through sanitization
        $this->corpsHtml = strip_tags($corps, '<p><br><strong><em><u><ul><ol><li><a><h1><h2><h3><h4><span><div><table><tr><td><th>');
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
        $blocLiens = '<p style="text-align: center; margin: 25px 0;">'
            .'<a href="'.$this->formulaireUrl.'" style="display:inline-block;padding:10px 24px;background:#3d5473;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Accéder au formulaire</a>'
            .'</p>'
            .'<p>Vous pouvez aussi saisir ce code sur la page d\'accueil du formulaire :</p>'
            .'<p style="text-align: center;">'
            .'<span style="display:inline-block;padding:8px 16px;background:#f0f0f0;border:1px solid #ddd;border-radius:6px;font-size:1.4rem;font-family:monospace;letter-spacing:3px;">'.$this->tokenCode.'</span>'
            .'</p>';

        return [
            '{bloc_liens}' => $blocLiens,
            '{url}' => $this->formulaireUrl,
            '{code}' => $this->tokenCode,
            '{date_expiration}' => $this->dateExpiration,
            '{prenom}' => $this->prenomParticipant,
            '{nom}' => $this->nomParticipant,
            '{operation}' => $this->nomOperation,
            '{type_operation}' => $this->nomTypeOperation,
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
