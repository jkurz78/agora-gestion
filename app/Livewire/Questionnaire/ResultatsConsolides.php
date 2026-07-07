<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\StatutSubmission;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireSubmission;
use App\Services\Questionnaire\QuestionnaireResultatService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class ResultatsConsolides extends Component
{
    /** @var array<int> */
    #[Url]
    public array $campagneIds = [];

    public function render(QuestionnaireResultatService $service): View
    {
        $campagnes = QuestionnaireCampaign::whereIn('id', $this->campagneIds)
            ->with('operation')
            ->get();

        $resultats = $campagnes->isNotEmpty()
            ? $service->pourCampagnes($campagnes)
            : null;

        $toutesLesCampagnes = QuestionnaireCampaign::with('operation')
            ->withCount([
                'invitations',
                'submissions as soumises_count' => fn ($q) => $q->where('statut', 'soumise'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (QuestionnaireCampaign $c): string => $c->titre_affiche ?: $c->titre);

        $contacts = collect();
        if ($campagnes->isNotEmpty()) {
            $contacts = QuestionnaireSubmission::whereIn('campaign_id', $campagnes->pluck('id'))
                ->where('statut', StatutSubmission::Soumise->value)
                ->where('accepte_contact', true)
                ->with('invitation.participant.tiers')
                ->get();
        }

        return view('livewire.questionnaire.resultats-consolides', [
            'campagnes' => $campagnes,
            'resultats' => $resultats,
            'toutesLesCampagnes' => $toutesLesCampagnes,
            'contacts' => $contacts,
        ]);
    }
}
