<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnaireInvitation;
use App\Services\Questionnaire\QuestionnaireCampaignService;
use App\Services\Questionnaire\QuestionnaireReponseService;
use App\Services\Questionnaire\QuestionnaireScanService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class CampagneShow extends Component
{
    use WithFileUploads;

    private const ONGLETS = ['suivi', 'diffusion', 'scans', 'resultats'];

    public QuestionnaireCampaign $campagne;

    #[Url(as: 'tab')]
    public string $tab = 'suivi';

    public ?int $scanPourInvitationId = null;

    /** @var TemporaryUploadedFile|null */
    public $scanFichier = null;

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
        if (! in_array($this->tab, self::ONGLETS, true)) {
            $this->tab = 'suivi';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::ONGLETS, true)) {
            $this->tab = $tab;
            $this->scanPourInvitationId = null;
            $this->reset('scanFichier');
        }
    }

    public function render(): View
    {
        $this->campagne->loadCount([
            'invitations',
            'submissions as soumises_count' => fn ($q) => $q->where('statut', 'soumise'),
        ]);

        $invitations = $this->campagne->invitations()
            ->with('participant.tiers')
            ->get()
            ->sortBy(fn ($i) => $i->participant?->tiers?->nom)
            ->values();

        $scansATraiter = $this->campagne->paperScans()
            ->whereHas('ocrDraft', fn ($q) => $q->where('statut', 'brouillon'))
            ->count();

        return view('livewire.questionnaire.campagne-show', [
            'invitations' => $invitations,
            'scansATraiter' => $scansATraiter,
        ]);
    }

    public function ouvrir(QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->ouvrir($this->campagne);
        $this->campagne->refresh();
    }

    public function cloturer(QuestionnaireCampaignService $campagnes): void
    {
        $campagnes->cloturer($this->campagne);
        $this->campagne->refresh();
    }

    public function rouvrirInvitation(int $invitationId, QuestionnaireReponseService $reponses): void
    {
        $reponses->rouvrir($this->invitation($invitationId));
    }

    public function ouvrirScanPour(int $invitationId): void
    {
        $this->scanPourInvitationId = (int) $this->invitation($invitationId)->id;
        $this->reset('scanFichier');
    }

    public function importerScanPour(QuestionnaireScanService $scanService): void
    {
        $this->validate(['scanFichier' => 'required|file|mimes:png,jpg,jpeg,pdf|max:10240']);

        $invitation = $this->invitation((int) $this->scanPourInvitationId);
        $scanService->ingererPourInvitation($this->scanFichier, $invitation);

        $this->scanPourInvitationId = null;
        $this->reset('scanFichier');
        session()->flash('scan_ok', 'Scan importé et attribué au participant.');
    }

    private function invitation(int $id): QuestionnaireInvitation
    {
        return $this->campagne->invitations()->findOrFail($id);
    }
}
