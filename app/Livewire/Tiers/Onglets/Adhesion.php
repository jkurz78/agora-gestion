<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Models\Adhesion as AdhesionModel;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\RecuFiscalService;
use App\Services\Tiers\TiersAdhesionTimelineService;
use App\Tenant\TenantContext;
use Illuminate\View\View;
use Livewire\Component;

final class Adhesion extends Component
{
    public Tiers $tiers;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function emettreRecuFiscalAdhesion(int $adhesionId): void
    {
        $adhesion = AdhesionModel::findOrFail($adhesionId);
        $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion, auth()->user());
        $this->redirect(route('tiers.recu-fiscal.download', ['recu' => $recu]));
    }

    public function render(): View
    {
        $dto = app(TiersAdhesionTimelineService::class)->forTiers($this->tiers);
        $asso = Association::findOrFail(TenantContext::currentId());

        return view('livewire.tiers.onglets.adhesion', compact('dto', 'asso'));
    }
}
