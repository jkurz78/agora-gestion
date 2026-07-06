<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\StatutSubmission;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnairePaperScan;
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

        $submissions = $this->campagne->submissions()
            ->where('statut', StatutSubmission::Soumise->value)
            ->with(['answers.question', 'invitation.participant.tiers'])
            ->orderByDesc('submitted_at')
            ->get();

        $questions = $this->campagne->questions()->orderBy('ordre')->get();

        $scans = QuestionnairePaperScan::where('campaign_id', (int) $this->campagne->id)
            ->where('statut', 'traite')
            ->orderBy('created_at')
            ->get();

        $scanParSubmission = collect();
        $scansParId = $scans->keyBy(fn ($s) => (int) $s->id);
        $scansParInvitation = $scans->whereNotNull('invitation_id')->keyBy(fn ($s) => (int) $s->invitation_id);

        foreach ($submissions->where('source', 'papier') as $sub) {
            if ($sub->paper_scan_id !== null && $scansParId->has((int) $sub->paper_scan_id)) {
                $scanParSubmission[(int) $sub->id] = $scansParId->get((int) $sub->paper_scan_id);
            } elseif ($sub->invitation_id !== null && $scansParInvitation->has((int) $sub->invitation_id)) {
                // Repli fiable pour l'historique d'avant paper_scan_id (scans nominatifs)
                $scanParSubmission[(int) $sub->id] = $scansParInvitation->get((int) $sub->invitation_id);
            }
            // Bearer historique sans lien : pas de badge scan (jamais d'appariement positionnel)
        }

        return view('livewire.questionnaire.campagne-resultats', [
            'resultats' => $service->pourCampagne($this->campagne),
            'contacts' => $contacts,
            'submissions' => $submissions,
            'questions' => $questions,
            'scanParSubmission' => $scanParSubmission,
        ]);
    }
}
