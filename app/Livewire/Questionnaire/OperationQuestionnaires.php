<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\Operation;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireTemplate;
use App\Services\Questionnaire\QuestionnaireCampaignService;
use App\Services\Questionnaire\QuestionnaireInvitationService;
use Illuminate\View\View;
use Livewire\Component;

final class OperationQuestionnaires extends Component
{
    public Operation $operation;

    public ?int $selectedTemplateId = null;

    /** @var array<int> */
    public array $selectedParticipants = [];

    public bool $showCreate = false;

    public function mount(Operation $operation): void
    {
        $this->operation = $operation;
        // Défaut D5 : tous les participants présélectionnés.
        $this->selectedParticipants = $operation->participants()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function render(): View
    {
        return view('livewire.questionnaire.operation-questionnaires', [
            // NB : pas de comptage des soumissions ici — la table questionnaire_submissions
            // n'existe qu'à partir du lot 3. Le compteur soumises/taux + le lien « Résultats »
            // sont ajoutés au lot 4 (withCount('submissions as soumises_count') à ce moment-là).
            'campagnes' => $this->operation->questionnaireCampaigns()
                ->withCount('invitations')
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

    private function campagne(int $id): QuestionnaireCampaign
    {
        return $this->operation->questionnaireCampaigns()->findOrFail($id);
    }
}
