<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaire\QuestionnaireCampaignService;
use App\Services\Questionnaire\QuestionnaireInvitationService;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Illuminate\View\View;
use Livewire\Component;

final class OperationQuestionnaires extends Component
{
    public Operation $operation;

    public ?int $selectedTemplateId = null;

    /** @var array<int> */
    public array $selectedParticipants = [];

    public bool $showCreate = false;

    public ?int $impressionCampagneId = null;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        // Défaut D5 : tous les participants présélectionnés.
        $this->selectedParticipants = $operation->participants()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function toggleImpression(int $campagneId): void
    {
        $this->impressionCampagneId = $this->impressionCampagneId === $campagneId ? null : $campagneId;
    }

    public function render(): View
    {
        return view('livewire.questionnaire.operation-questionnaires', [
            'impressionCampagneId' => $this->impressionCampagneId,
            'campagnes' => $this->operation->questionnaireCampaigns()
                ->withCount([
                    'invitations',
                    'submissions as soumises_count' => fn ($q) => $q->where('statut', 'soumise'),
                ])
                ->with(['invitations.participant.tiers'])
                ->latest()
                ->get(),
            'modeles' => QuestionnaireTemplate::where('actif', true)->orderBy('titre_interne')->get(),
            'participants' => $this->operation->participants()->with('tiers')->get(),
        ]);
    }

    public function creerCampagne(
        QuestionnaireCampaignService $campagnes,
        QuestionnaireInvitationService $invitations,
    ): void {
        $this->validate(['selectedTemplateId' => 'required|exists:questionnaire_templates,id']);

        $modele = QuestionnaireTemplate::findOrFail($this->selectedTemplateId);
        $campagne = $campagnes->creerDepuisModele($this->operation, $modele);
        $invitations->genererPour($campagne, $this->selectedParticipants);

        $this->showCreate = false;
        $this->reset('selectedTemplateId');
    }

    public function ouvrir(int $campagneId, QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->ouvrir($this->campagne($campagneId));
    }

    public function cloturer(int $campagneId, QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->cloturer($this->campagne($campagneId));
    }

    public function rouvrirInvitation(int $invitationId, QuestionnaireReponseService $reponses): void
    {
        $invitation = QuestionnaireInvitation::whereHas(
            'campaign',
            fn ($q) => $q->where('operation_id', $this->operation->id),
        )->findOrFail($invitationId);

        $reponses->rouvrir($invitation);
    }

    private function campagne(int $id): QuestionnaireCampaign
    {
        return $this->operation->questionnaireCampaigns()->findOrFail($id);
    }
}
