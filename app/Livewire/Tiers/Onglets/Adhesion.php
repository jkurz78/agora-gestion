<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Exceptions\RecuFiscalException;
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

    public bool $showHelloAssoWarning = false;

    public ?int $pendingAdhesionId = null;

    public ?string $recuFiscalError = null;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    public function emettreRecuFiscalAdhesion(int $adhesionId): void
    {
        $this->recuFiscalError = null;
        $adhesion = AdhesionModel::findOrFail($adhesionId);

        if ($adhesion->formuleAdhesion?->est_helloasso) {
            $this->pendingAdhesionId = $adhesionId;
            $this->showHelloAssoWarning = true;

            return;
        }

        $this->genererEtRedirect($adhesion);
    }

    public function confirmEmettreRecuApresAvertissement(): void
    {
        if ($this->pendingAdhesionId === null) {
            return;
        }

        $adhesion = AdhesionModel::findOrFail($this->pendingAdhesionId);
        $this->showHelloAssoWarning = false;
        $this->pendingAdhesionId = null;
        $this->recuFiscalError = null;

        $this->genererEtRedirect($adhesion);
    }

    public function dismissRecuFiscalError(): void
    {
        $this->recuFiscalError = null;
    }

    public function cancelEmettreRecuApresAvertissement(): void
    {
        $this->showHelloAssoWarning = false;
        $this->pendingAdhesionId = null;
    }

    public function render(): View
    {
        $dto = app(TiersAdhesionTimelineService::class)->forTiers($this->tiers);
        $asso = Association::findOrFail(TenantContext::currentId());

        return view('livewire.tiers.onglets.adhesion', compact('dto', 'asso'));
    }

    private function genererEtRedirect(AdhesionModel $adhesion): void
    {
        try {
            $recu = app(RecuFiscalService::class)->obtenirOuGenererPourAdhesion($adhesion, auth()->user());
        } catch (RecuFiscalException $e) {
            // Erreur métier explicite (asso non éligible, signataire manquant, adresse
            // donateur incomplète, adhésion non déductible, etc.) → on expose le message
            // via une propriété publique plutôt qu'une exception 500.
            $this->recuFiscalError = $e->getMessage();

            return;
        }

        $this->redirect(route('tiers.recu-fiscal.download', ['recu' => $recu]));
    }
}
