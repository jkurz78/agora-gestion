<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use App\Services\RecuFiscalService;
use App\Services\TiersQuickViewService;
use App\Tenant\TenantContext;
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

    public ?int $ligneAvecAvertissement = null;

    /** @var array<int, string> */
    public array $avertissementsActifs = [];

    public bool $showModaleAvertissement = false;

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

    public function afficherAvertissements(int $ligneId): void
    {
        $this->ligneAvecAvertissement = $ligneId;
        $don = TransactionLigne::with('transaction')->findOrFail($ligneId);
        $tiers = Tiers::findOrFail($this->tiersId);
        $asso = Association::findOrFail(TenantContext::currentId());

        $alertes = [];

        if ($don->transaction->helloasso_payment_id !== null) {
            $alertes[] = 'helloasso';
        }

        if (
            ($asso->updated_at !== null && $asso->updated_at->gt($don->transaction->date))
            || ($tiers->updated_at !== null && $tiers->updated_at->gt($don->transaction->date))
        ) {
            $alertes[] = 'donnees_modifiees';
        }

        $this->avertissementsActifs = $alertes;
        $this->showModaleAvertissement = true;
    }

    public function continuerTelechargement(): void
    {
        $tiers = Tiers::findOrFail($this->tiersId);
        $url = route('tiers.dons.recu-fiscal', ['tiers' => $tiers, 'ligne' => $this->ligneAvecAvertissement]);
        $this->showModaleAvertissement = false;
        $this->ligneAvecAvertissement = null;
        $this->avertissementsActifs = [];
        $this->redirect($url);
    }

    public function fermerModaleAvertissement(): void
    {
        $this->showModaleAvertissement = false;
        $this->ligneAvecAvertissement = null;
        $this->avertissementsActifs = [];
    }

    public function render(): View
    {
        $tiers = $this->tiersId !== null ? Tiers::find($this->tiersId) : null;
        $availableYears = app(ExerciceService::class)->availableYears();

        $dons = collect();
        $recusParLigne = collect();
        $alertesParLigne = collect();

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

            $asso = Association::findOrFail(TenantContext::currentId());
            $alertesParLigne = $dons->mapWithKeys(function (TransactionLigne $don) use ($asso, $tiers): array {
                $alertes = [];

                if ($don->transaction->helloasso_payment_id !== null) {
                    $alertes[] = 'helloasso';
                }

                if (
                    ($asso->updated_at !== null && $asso->updated_at->gt($don->transaction->date))
                    || ($tiers->updated_at !== null && $tiers->updated_at->gt($don->transaction->date))
                ) {
                    $alertes[] = 'donnees_modifiees';
                }

                return [$don->id => $alertes];
            });
        }

        return view('livewire.tiers-quick-view', compact('tiers', 'availableYears', 'dons', 'recusParLigne', 'alertesParLigne'));
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
