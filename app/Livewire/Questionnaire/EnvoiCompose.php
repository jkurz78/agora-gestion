<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireEnvoiService;
use App\Services\Questionnaire\QuestionnaireInvitationService;
use Illuminate\View\View;
use Livewire\Component;

final class EnvoiCompose extends Component
{
    public const CORPS_DEFAUT = '<p>Bonjour {prenom},</p>'
        .'<p>Nous vous invitons à répondre à notre questionnaire de satisfaction.</p>'
        .'<p><a href="{lien_questionnaire}">Accéder au questionnaire</a></p>'
        .'<p>Merci pour votre retour !</p>';

    public QuestionnaireCampaign $campagne;

    public string $objet = '';

    public string $corps = '';

    /** @var array<int> */
    public array $selectedParticipants = [];

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
        $this->corps = self::CORPS_DEFAUT;

        // Présélectionner tous les participants.
        $this->selectedParticipants = $campagne->operation
            ->participants()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function toggleTousParticipants(): void
    {
        $avecEmail = $this->campagne->operation
            ->participants()
            ->with('tiers')
            ->get()
            ->filter(fn ($p) => ! empty($p->tiers?->email));

        if (count($this->selectedParticipants) === $avecEmail->count() && $avecEmail->count() > 0) {
            $this->selectedParticipants = [];
        } else {
            $this->selectedParticipants = $avecEmail->pluck('id')->map(fn ($id) => (int) $id)->all();
        }
    }

    public function render(): View
    {
        $participants = $this->campagne->operation->participants()->with('tiers')->get();

        return view('livewire.questionnaire.envoi-compose', [
            'participants' => $participants,
            'participantsAvecEmailCount' => $participants->filter(fn ($p) => ! empty($p->tiers?->email))->count(),
        ]);
    }

    public function envoyer(
        QuestionnaireInvitationService $invitationService,
        QuestionnaireEnvoiService $envoiService,
    ): void {
        $this->validate([
            'objet' => 'required|string|max:255',
            'corps' => 'required|string',
            'selectedParticipants' => 'array',
        ]);

        // Générer les invitations manquantes (idempotent).
        $invitationService->genererPour($this->campagne, $this->selectedParticipants);

        // Récupérer les IDs d'invitations correspondant aux participants sélectionnés.
        $invitationIds = $this->campagne
            ->invitations()
            ->whereIn('participant_id', $this->selectedParticipants)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $envoiService->envoyer($this->campagne, $invitationIds, $this->objet, $this->corps);

        session()->flash('envoi_ok', count($invitationIds).' invitation(s) envoyée(s).');
    }

    public function relancer(QuestionnaireEnvoiService $envoiService): void
    {
        // Présélectionner les non-soumis pour la relance.
        $this->selectedParticipants = $this->campagne
            ->invitations()
            ->where('statut', '!=', 'soumis')
            ->join('participants', 'participants.id', '=', 'questionnaire_invitations.participant_id')
            ->pluck('participants.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
