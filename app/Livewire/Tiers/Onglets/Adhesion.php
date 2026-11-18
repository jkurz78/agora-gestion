<?php

declare(strict_types=1);

namespace App\Livewire\Tiers\Onglets;

use App\Enums\Espace;
use App\Enums\RoleAssociation;
use App\Exceptions\RecuFiscalException;
use App\Models\Adhesion as AdhesionModel;
use App\Models\Association;
use App\Models\Tiers;
use App\Services\AdhesionService;
use App\Services\RecuFiscalService;
use App\Services\Tiers\TiersAdhesionTimelineService;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class Adhesion extends Component
{
    public Tiers $tiers;

    public bool $showHelloAssoWarning = false;

    public ?int $pendingAdhesionId = null;

    public ?string $recuFiscalError = null;

    /** Adhésion offerte dont la suppression attend confirmation (modale). */
    public ?int $adhesionASupprimerId = null;

    public ?string $actionError = null;

    public function mount(Tiers $tiers): void
    {
        $this->tiers = $tiers;
    }

    /** L'assistant (autre composant) a modifié une adhésion : le rendu suit. */
    #[On('adhesion-modifiee')]
    public function rafraichir(): void {}

    public function demanderSuppression(int $adhesionId): void
    {
        $this->exigerEcriture();
        $this->actionError = null;
        $this->adhesionASupprimerId = $adhesionId;
    }

    public function annulerSuppression(): void
    {
        $this->adhesionASupprimerId = null;
    }

    public function confirmerSuppression(AdhesionService $service): void
    {
        $this->exigerEcriture();

        if ($this->adhesionASupprimerId === null) {
            return;
        }

        $adhesion = AdhesionModel::where('tiers_id', (int) $this->tiers->id)->findOrFail($this->adhesionASupprimerId);
        $this->adhesionASupprimerId = null;

        try {
            $service->supprimerOfferte($adhesion);
        } catch (DomainException $e) {
            $this->actionError = $e->getMessage();

            return;
        }

        // La fiche tiers (composant parent) porte le compteur de l'onglet.
        $this->dispatch('adhesion-supprimee');
    }

    public function dismissActionError(): void
    {
        $this->actionError = null;
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

        $peutModifier = $this->peutModifier();

        return view('livewire.tiers.onglets.adhesion', compact('dto', 'asso', 'peutModifier'));
    }

    private function peutModifier(): bool
    {
        return RoleAssociation::tryFrom(Auth::user()?->currentRole() ?? '')?->canWrite(Espace::Gestion) ?? false;
    }

    private function exigerEcriture(): void
    {
        if (! $this->peutModifier()) {
            abort(403);
        }
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
