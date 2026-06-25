<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\StatutSubmission;
use App\Models\QuestionnaireCampaign;
use App\Services\Questionnaire\QuestionnaireResultatService;
use Illuminate\View\View;
use Livewire\Component;

final class CampagneResultats extends Component
{
    public QuestionnaireCampaign $campagne;

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
    }

    public function render(QuestionnaireResultatService $service): View
    {
        $query = $this->campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with('invitation.participant.tiers');

        // Non anonyme : toutes les soumissions ; anonyme : uniquement celles ayant consenti (D9/D10).
        if ($this->campagne->anonymise) {
            $query->where('accepte_contact', true);
        }

        $contacts = $query->get();

        return view('livewire.questionnaire.campagne-resultats', [
            'resultats' => $service->pourCampagne($this->campagne),
            'contacts' => $contacts,
        ]);
    }
}
