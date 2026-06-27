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
use App\Services\Questionnaire\QuestionnaireScanService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class OperationQuestionnaires extends Component
{
    use WithFileUploads;

    public Operation $operation;

    public ?int $selectedTemplateId = null;

    /** @var array<int> */
    public array $selectedParticipants = [];

    public bool $showCreate = false;

    public ?int $showParticipantsCampagneId = null;

    public ?int $scanPourInvitationId = null;

    /** @var TemporaryUploadedFile|null */
    public $scanFichier = null;

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
            ->with(['invitations.participant.tiers'])
            ->latest()
            ->get();

        $campagneModale = null;
        if ($this->showParticipantsCampagneId !== null) {
            $campagneModale = $campagnes->firstWhere('id', $this->showParticipantsCampagneId);
        }

        return view('livewire.questionnaire.operation-questionnaires', [
            'campagnes' => $campagnes,
            'campagneModale' => $campagneModale,
            'modeles' => QuestionnaireTemplate::where('actif', true)->orderBy('titre_interne')->get(),
            'participants' => $this->operation->participants()->with('tiers')->get(),
        ]);
    }

    public function ouvrirParticipants(int $campagneId): void
    {
        $this->showParticipantsCampagneId = $campagneId;
        $this->scanPourInvitationId = null;
        $this->reset('scanFichier');
    }

    public function fermerParticipants(): void
    {
        $this->showParticipantsCampagneId = null;
        $this->scanPourInvitationId = null;
        $this->reset('scanFichier');
    }

    public function ouvrirScanPour(int $invitationId): void
    {
        $this->scanPourInvitationId = $invitationId;
        $this->reset('scanFichier');
    }

    public function importerScanPour(QuestionnaireScanService $scanService): void
    {
        $this->validate(['scanFichier' => 'required|file|mimes:png,jpg,jpeg,pdf|max:10240']);

        $invitation = QuestionnaireInvitation::whereHas(
            'campaign',
            fn ($q) => $q->where('operation_id', $this->operation->id),
        )->findOrFail($this->scanPourInvitationId);

        $scanService->ingererPourInvitation($this->scanFichier, $invitation);

        $this->scanPourInvitationId = null;
        $this->reset('scanFichier');
        session()->flash('scan_ok', 'Scan importé et attribué au participant.');
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
