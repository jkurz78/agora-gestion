<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Enums\TypeQuestion;
use App\Models\Participant;
use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireOcrDraft;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireReponseService;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;

final class AssistantSaisie extends Component
{
    public QuestionnairePaperScan $scan;

    public QuestionnaireOcrDraft $draft;

    /** @var array<string, mixed> Values keyed by question_id */
    public array $valeurs = [];

    /** @var array<string, string> Commentaires/texte long keyed by question_id */
    public array $commentaires = [];

    public bool $accepteContact = false;

    public string $contactNom = '';

    public ?int $participantId = null;

    public bool $showRemplacer = false;

    public function mount(QuestionnairePaperScan $scan): void
    {
        $this->scan = $scan;
        $this->draft = $scan->ocrDraft;

        foreach ($this->draft->payload as $qid => $entry) {
            if ($qid === '_accepte_contact') {
                $this->accepteContact = ! empty($entry['value']);

                continue;
            }
            if ($qid === '_nom_transcrit') {
                $this->contactNom = trim((string) ($entry['value'] ?? ''));

                continue;
            }
            $this->valeurs[(string) $qid] = $entry['value'] ?? '';
            if (isset($entry['text']) && $entry['text'] !== null) {
                $this->commentaires[(string) $qid] = (string) $entry['text'];
            }
        }

        foreach ($scan->campaign->questions as $q) {
            if ($q->type === TypeQuestion::ChoixMultiple) {
                $qid = (string) $q->id;
                if (! is_array($this->valeurs[$qid] ?? null)) {
                    $this->valeurs[$qid] = [];
                }
            }
        }

        if ($this->accepteContact && $this->contactNom !== '' && $scan->bearerToken !== null) {
            $this->preselectionnerParticipant($scan->campaign);
        }
    }

    private function preselectionnerParticipant(QuestionnaireCampaign $campagne): void
    {
        $normalise = Str::lower($this->contactNom);

        $participants = Participant::where('operation_id', (int) $campagne->operation_id)
            ->with('tiers')
            ->get();

        foreach ($participants as $p) {
            $tiers = $p->tiers;
            if ($tiers === null) {
                continue;
            }
            $prenomNom = Str::lower(trim(($tiers->prenom ?? '').' '.($tiers->nom ?? '')));
            $nomPrenom = Str::lower(trim(($tiers->nom ?? '').' '.($tiers->prenom ?? '')));

            if ($prenomNom === $normalise || $nomPrenom === $normalise) {
                $this->participantId = (int) $p->id;

                return;
            }
        }
    }

    public function render(): View
    {
        $campagne = $this->scan->campaign;
        $campagne->loadMissing('questions');
        $invitation = $this->scan->invitation;
        $bearer = $this->scan->bearerToken;

        $hasExisting = false;
        if ($invitation !== null) {
            $hasExisting = $invitation->submissions()
                ->whereIn('statut', ['en_cours', 'soumise'])
                ->exists();
        }

        $participantsListe = collect();
        if ($bearer !== null) {
            $participantsListe = Participant::where('operation_id', (int) $campagne->operation_id)
                ->with('tiers')
                ->get()
                ->map(fn ($p) => ['id' => $p->id, 'nom' => $p->tiers?->displayName() ?? '—'])
                ->sortBy('nom')
                ->values();
        }

        return view('livewire.questionnaire.assistant-saisie', [
            'campagne' => $campagne,
            'questions' => $campagne->questions()->orderBy('ordre')->get(),
            'invitation' => $invitation,
            'participant' => $invitation?->participant?->tiers,
            'hasExisting' => $hasExisting,
            'payload' => $this->draft->payload,
            'participantsListe' => $participantsListe,
        ]);
    }

    public function valider(QuestionnaireReponseService $service): void
    {
        $invitation = $this->scan->invitation;
        $bearer = $this->scan->bearerToken;

        abort_unless($invitation !== null || $bearer !== null, 422, 'Scan non rattaché.');

        $nomContact = trim($this->contactNom) !== '' ? trim($this->contactNom) : null;

        if ($invitation !== null) {
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
                commentairesParQuestionId: $this->commentaires,
                accepteContact: $this->accepteContact,
                remplacer: $hasExisting,
                paperScanId: (int) $this->scan->id,
            );
        } else {
            $service->creerDepuisOcrAnonyme(
                bearer: $bearer,
                valeursParQuestionId: $this->valeurs,
                commentairesParQuestionId: $this->commentaires,
                accepteContact: $this->accepteContact,
                contactNom: $nomContact,
                participantId: $this->accepteContact ? $this->participantId : null,
                paperScanId: (int) $this->scan->id,
            );
        }

        $this->draft->update(['statut' => 'valide']);
        $this->scan->update(['statut' => 'traite']);

        session()->flash('scan_ok', 'Réponse papier enregistrée.');
        $this->redirect(route('questionnaires.campagnes.show', ['campagne' => $this->scan->campaign_id, 'tab' => 'scans']));
    }

    public function ignorer(): void
    {
        $this->draft->update(['statut' => 'rejete']);
        $this->scan->update(['statut' => 'ignore']);

        session()->flash('scan_ok', 'Scan ignoré.');
        $this->redirect(route('questionnaires.campagnes.show', ['campagne' => $this->scan->campaign_id, 'tab' => 'scans']));
    }
}
