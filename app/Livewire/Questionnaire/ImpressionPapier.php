<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireImpressionService;
use Illuminate\View\View;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ImpressionPapier extends Component
{
    public QuestionnaireCampaign $campagne;

    /** @var array<int> */
    public array $selectedParticipants = [];

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;

        // Présélectionner tous les participants.
        $this->selectedParticipants = $campagne->operation
            ->participants()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function render(): View
    {
        return view('livewire.questionnaire.impression-papier', [
            'participants' => $this->campagne->operation->participants()->with('tiers')->get(),
        ]);
    }

    public function imprimer(QuestionnaireImpressionService $service): StreamedResponse
    {
        $this->validate(['selectedParticipants' => 'array']);

        return $service->telecharger($this->campagne, $this->selectedParticipants);
    }
}
