<?php

declare(strict_types=1);

namespace App\Livewire\Questionnaire;

use App\Models\QuestionnaireCampaign;
use App\Models\QuestionnairePaperScan;
use App\Services\Questionnaire\QuestionnaireScanService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

final class ScanUpload extends Component
{
    use WithFileUploads;

    public QuestionnaireCampaign $campagne;

    /** @var TemporaryUploadedFile|null */
    public $fichier = null;

    public function mount(QuestionnaireCampaign $campagne): void
    {
        $this->campagne = $campagne;
    }

    public function render(): View
    {
        return view('livewire.questionnaire.scan-upload', [
            'scans' => QuestionnairePaperScan::where('campaign_id', $this->campagne->id)
                ->with(['invitation.participant.tiers', 'ocrDraft'])
                ->latest()
                ->get(),
        ]);
    }

    public function importer(QuestionnaireScanService $service): void
    {
        $this->validate([
            'fichier' => 'required|file|mimes:png,jpg,jpeg,pdf|max:10240',
        ]);

        $service->ingererUpload($this->fichier, (int) $this->campagne->id);

        $this->reset('fichier');
        session()->flash('scan_ok', 'Scan importé avec succès.');
    }

    public function supprimerScan(int $id): void
    {
        $scan = QuestionnairePaperScan::where('campaign_id', $this->campagne->id)->findOrFail($id);

        $scan->ocrDraft?->delete();

        $tenantPath = 'associations/'.TenantContext::currentId().'/'.$scan->chemin_fichier;
        Storage::disk('local')->delete($tenantPath);

        $scan->delete();

        session()->flash('scan_ok', 'Scan supprimé.');
    }
}
