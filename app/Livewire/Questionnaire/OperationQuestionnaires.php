<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\Operation;
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
        $this->selectedParticipants = $operation->participants()->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function render(): View
    {
        $campagnes = $this->operation->questionnaireCampaigns()
            ->withCount([
                'invitations',
                'submissions as soumises_count' => fn ($q) => $q->where('statut', 'soumise'),
            ])
            ->latest()
            ->get();

        return view('livewire.questionnaire.operation-questionnaires', [
            'campagnes' => $campagnes,
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

        $this->redirect(route('questionnaires.campagnes.show', $campagne));
    }
}
