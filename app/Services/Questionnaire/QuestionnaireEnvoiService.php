<?php

declare(strict_types=1);

namespace App\Services\Questionnaire;

use App\Mail\QuestionnaireInvitationMail;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

final class QuestionnaireEnvoiService
{
    public function __construct(
        private readonly QuestionnaireVariableResolver $resolver,
    ) {}

    /**
     * Envoie les invitations ciblées par leurs IDs.
     *
     * Assainit le corps une seule fois, puis résout les variables par invitation.
     *
     * @param  array<int>  $invitationIds
     */
    public function envoyer(
        QuestionnaireCampaign $campagne,
        array $invitationIds,
        string $objet,
        string $corps,
    ): void {
        // Assainir le corps une seule fois (protège {var} dans href).
        $corpsSain = EmailTemplate::sanitizeCorps($corps);

        $invitations = QuestionnaireInvitation::whereIn('id', $invitationIds)
            ->where('campaign_id', $campagne->id)
            ->with(['participant.tiers', 'campaign.operation'])
            ->get();

        foreach ($invitations as $invitation) {
            $tiers = $invitation->participant?->tiers;
            $email = $tiers?->email;

            // Skip si pas d'email.
            if (! $email) {
                continue;
            }

            // Résoudre les variables pour ce destinataire (lien_questionnaire inclus).
            $vars = $this->resolver->pour($invitation, avecLien: true);

            $objetRendu = $this->resolver->remplacer($objet, $vars);
            $corpsRendu = $this->resolver->remplacer($corpsSain, $vars);

            $mailable = new QuestionnaireInvitationMail(
                objet: $objetRendu,
                corpsHtml: $corpsRendu,
            );

            Mail::to($email)->send($mailable);

            EmailLog::create([
                'tiers_id' => $tiers->id,
                'participant_id' => $invitation->participant_id,
                'operation_id' => $campagne->operation_id,
                'categorie' => 'message',
                'destinataire_email' => $email,
                'destinataire_nom' => $tiers->displayName(),
                'objet' => $objet,
                'objet_rendu' => $objetRendu,
                'corps_html' => $corpsRendu,
                'statut' => 'envoye',
                'envoye_par' => Auth::id(),
                // campagne_id référence campagne_emails — laisser null ici.
            ]);

            $invitation->update(['sent_at' => now()]);
        }
    }

    /**
     * Retourne les IDs d'invitations non soumises pour la campagne (relance).
     *
     * @return array<int>
     */
    public function idsNonSoumis(QuestionnaireCampaign $campagne): array
    {
        return $campagne->invitations()
            ->where('statut', '!=', 'soumis')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
