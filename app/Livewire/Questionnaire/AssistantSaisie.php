<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Illuminate\View\View;
use Livewire\Component;

final class AssistantSaisie extends Component
{
    public QuestionnairePaperScan $scan;

    public QuestionnaireOcrDraft $draft;

    /** @var array<string, mixed> Values keyed by question_id */
    public array $valeurs = [];

    public bool $accepteContact = false;

    public bool $showRemplacer = false;

    public function mount(QuestionnairePaperScan $scan): void
    {
        $this->scan = $scan;
        $this->draft = $scan->ocrDraft;

        // Pre-fill values from OCR payload
        foreach ($this->draft->payload as $qid => $entry) {
            $this->valeurs[(string) $qid] = $entry['value'] ?? '';
        }
    }

    public function render(): View
    {
        $campagne = $this->scan->campaign;
        $campagne->loadMissing('questions');
        $invitation = $this->scan->invitation;

        $hasExisting = $invitation?->submissions()
            ->whereIn('statut', ['en_cours', 'soumise'])
            ->exists() ?? false;

        return view('livewire.questionnaire.assistant-saisie', [
            'campagne' => $campagne,
            'questions' => $campagne->questions()->orderBy('ordre')->get(),
            'invitation' => $invitation,
            'participant' => $invitation?->participant?->tiers,
            'hasExisting' => $hasExisting,
            'payload' => $this->draft->payload,
        ]);
    }

    public function valider(QuestionnaireReponseService $service): void
    {
        $invitation = $this->scan->invitation;
        abort_unless($invitation !== null, 422, 'Scan non rattaché à une invitation.');

        $hasExisting = $invitation->submissions()
            ->whereIn('statut', ['en_cours', 'soumise'])
            ->exists();

        if ($hasExisting && ! $this->showRemplacer) {
            $this->showRemplacer = true;

            return;
        }

        $service->creerDepuisOcr(
            invitation: $invitation,
            valeursParQuestionId: $this->valeurs,
            accepteContact: $this->accepteContact,
            remplacer: $hasExisting,
        );

        $this->draft->update(['statut' => 'valide']);
        $this->scan->update(['statut' => 'traite']);

        session()->flash('scan_ok', 'Réponse papier enregistrée.');
        $this->redirect(route('questionnaires.campagnes.scans', $this->scan->campaign_id));
    }

    public function ignorer(): void
    {
        $this->draft->update(['statut' => 'rejete']);
        $this->scan->update(['statut' => 'ignore']);

        session()->flash('scan_ok', 'Scan ignoré.');
        $this->redirect(route('questionnaires.campagnes.scans', $this->scan->campaign_id));
    }
}
