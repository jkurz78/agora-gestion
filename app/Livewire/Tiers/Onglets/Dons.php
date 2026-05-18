<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Association;
use App\Models\RecuFiscalEmis;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\RecuFiscalService;
use App\Services\Tiers\TiersDonsTimelineService;
use App\Tenant\TenantContext;
use Illuminate\View\View;
use Livewire\Component;

final class Dons extends Component
{
    public Tiers $tiers;

    public ?int $recuAAnnuler = null;

    public string $motifAnnulation = '';

    public bool $showModaleAnnulation = false;

    public ?int $ligneAvecAvertissement = null;

    /** @var array<int, string> */
    public array $avertissementsActifs = [];

    public bool $showModaleAvertissement = false;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function ouvrirModaleAnnulation(int $recuId): void
    {
        $this->recuAAnnuler = $recuId;
        $this->motifAnnulation = '';
        $this->showModaleAnnulation = true;
    }

    public function fermerModaleAnnulation(): void
    {
        $this->recuAAnnuler = null;
        $this->motifAnnulation = '';
        $this->showModaleAnnulation = false;
    }

    public function confirmerReEmission(RecuFiscalService $service): void
    {
        if ($this->recuAAnnuler === null) {
            return;
        }
        $recu = RecuFiscalEmis::findOrFail($this->recuAAnnuler);
        abort_unless((int) $recu->tiers_id === (int) $this->tiers->id, 403);
        $service->reemettre($recu, $this->motifAnnulation, auth()->user());
        $this->fermerModaleAnnulation();
    }

    public function afficherAvertissements(int $ligneId): void
    {
        $don = TransactionLigne::with('transaction')->findOrFail($ligneId);
        abort_unless(
            $don->transaction !== null && (int) $don->transaction->tiers_id === (int) $this->tiers->id,
            403
        );
        $this->ligneAvecAvertissement = $ligneId;
        $asso = Association::findOrFail(TenantContext::currentId());

        $alertes = [];
        if ($don->transaction->helloasso_payment_id !== null) {
            $alertes[] = 'helloasso';
        }
        if (
            ($asso->updated_at !== null && $asso->updated_at->gt($don->transaction->created_at))
            || ($this->tiers->updated_at !== null && $this->tiers->updated_at->gt($don->transaction->created_at))
        ) {
            $alertes[] = 'donnees_modifiees';
        }

        $this->avertissementsActifs = $alertes;
        $this->showModaleAvertissement = true;
    }

    public function fermerModaleAvertissement(): void
    {
        $this->showModaleAvertissement = false;
        $this->ligneAvecAvertissement = null;
        $this->avertissementsActifs = [];
    }

    public function continuerTelechargement(): void
    {
        $url = route('tiers.dons.recu-fiscal', [
            'tiers' => $this->tiers,
            'ligne' => $this->ligneAvecAvertissement,
        ]);
        $this->fermerModaleAvertissement();
        $this->dispatch('open-new-tab', url: $url);
    }

    public function render(): View
    {
        $dto = app(TiersDonsTimelineService::class)->forTiers($this->tiers);

        return view('livewire.tiers.onglets.dons', [
            'dto' => $dto,
        ]);
    }
}
