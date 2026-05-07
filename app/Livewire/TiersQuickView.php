<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Services\RecuFiscalService;
use App\Services\TiersQuickViewService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class TiersQuickView extends Component
{
    public bool $visible = false;

    public ?int $tiersId = null;

    public int $exercice = 0;

    /** @var array<string, mixed> */
    public array $summary = [];

    public ?int $recuAAnnuler = null;

    public string $motifAnnulation = '';

    public bool $showModaleAnnulation = false;

    #[On('open-tiers-quick-view')]
    public function loadTiers(int $tiersId): void
    {
        $this->tiersId = $tiersId;
        $this->exercice = app(ExerciceService::class)->current();
        $this->fetchSummary();
        $this->visible = true;
    }

    public function updatedExercice(): void
    {
        $this->fetchSummary();
    }

    public function close(): void
    {
        $this->visible = false;
        $this->tiersId = null;
        $this->summary = [];
    }

    public function ouvrirModaleAnnulation(int $recuId): void
    {
        $this->recuAAnnuler = $recuId;
        $this->motifAnnulation = '';
        $this->showModaleAnnulation = true;
    }

    public function confirmerReEmission(RecuFiscalService $service): void
    {
        if ($this->recuAAnnuler === null) {
            return;
        }

        $recu = RecuFiscalEmis::findOrFail($this->recuAAnnuler);
        $service->reemettre($recu, $this->motifAnnulation, auth()->user());

        $this->recuAAnnuler = null;
        $this->motifAnnulation = '';
        $this->showModaleAnnulation = false;
    }

    public function fermerModaleAnnulation(): void
    {
        $this->recuAAnnuler = null;
        $this->motifAnnulation = '';
        $this->showModaleAnnulation = false;
    }

    public function render(): View
    {
        $tiers = $this->tiersId !== null ? Tiers::find($this->tiersId) : null;
        $availableYears = app(ExerciceService::class)->availableYears();

        $dons = collect();
        $recusParLigne = collect();

        if ($tiers !== null) {
            $donSousCategorieIds = SousCategorie::forUsage(UsageComptable::Don)->pluck('id');
            $dons = TransactionLigne::query()
                ->whereHas('transaction', fn ($q) => $q->where('tiers_id', $tiers->id))
                ->whereIn('sous_categorie_id', $donSousCategorieIds)
                ->with(['transaction', 'sousCategorie'])
                ->orderByDesc('id')
                ->get();

            $recusParLigne = RecuFiscalEmis::query()
                ->whereIn('transaction_ligne_id', $dons->pluck('id'))
                ->whereNull('annule_at')
                ->get()
                ->keyBy('transaction_ligne_id');
        }

        return view('livewire.tiers-quick-view', compact('tiers', 'availableYears', 'dons', 'recusParLigne'));
    }

    private function fetchSummary(): void
    {
        if ($this->tiersId === null) {
            $this->summary = [];

            return;
        }

        $tiers = Tiers::find($this->tiersId);

        if ($tiers === null) {
            $this->summary = [];

            return;
        }

        $this->summary = app(TiersQuickViewService::class)->getSummary($tiers, $this->exercice);
    }
}
