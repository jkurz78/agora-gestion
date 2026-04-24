<?php

declare(strict_types=1);

namespace App\Livewire\BackOffice\FacturePartenaire;

use App\Enums\StatutFactureDeposee;
use App\Models\FacturePartenaireDeposee;
use App\Services\Portail\FacturePartenaireService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

final class Index extends Component
{
    #[Url(as: 'onglet')]
    public string $onglet = 'a_traiter';

    public bool $showRejectModal = false;

    public ?int $depotIdToReject = null;

    public string $motifRejet = '';

    public function mount(): void
    {
        $this->authorize('treat', FacturePartenaireDeposee::class);
    }

    #[On('transaction-saved')]
    public function onTransactionSaved(): void
    {
        // No-op: Livewire re-renders the component automatically after any listener,
        // so the depot list refreshes without requiring a manual page reload.
    }

    public function render(): View
    {
        $depots = $this->queryDepots();

        return view('livewire.back-office.facture-partenaire.index', [
            'depots' => $depots,
            'onglet' => $this->onglet,
        ])->layout('layouts.app-sidebar', ['title' => 'Factures à comptabiliser']);
    }

    public function comptabiliser(int $depotId): void
    {
        $this->authorize('treat', FacturePartenaireDeposee::class);

        $depot = FacturePartenaireDeposee::find($depotId);
        if ($depot === null) {
            abort(404);
        }
        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            session()->flash('error', 'Ce dépôt n\'est plus à traiter (déjà traité ou rejeté).');

            return;
        }

        $this->dispatch('open-transaction-form-from-depot-facture', depotId: $depot->id);
    }

    public function ouvrirRejet(int $depotId): void
    {
        $this->authorize('treat', FacturePartenaireDeposee::class);

        $depot = FacturePartenaireDeposee::find($depotId);
        if ($depot === null) {
            abort(404);
        }
        if ($depot->statut !== StatutFactureDeposee::Soumise) {
            session()->flash('error', 'Ce dépôt n\'est plus à traiter.');

            return;
        }

        $this->depotIdToReject = (int) $depot->id;
        $this->motifRejet = '';
        $this->showRejectModal = true;
        $this->resetValidation('motifRejet');
    }

    public function fermerRejet(): void
    {
        $this->showRejectModal = false;
        $this->depotIdToReject = null;
        $this->motifRejet = '';
        $this->resetValidation('motifRejet');
    }

    public function confirmerRejet(): void
    {
        $this->validate([
            'motifRejet' => ['required', 'string', 'min:1', 'max:1000'],
        ], [
            'motifRejet.required' => 'Le motif est obligatoire.',
            'motifRejet.min' => 'Le motif est obligatoire.',
        ]);

        if ($this->depotIdToReject === null) {
            return;
        }

        $depot = FacturePartenaireDeposee::find($this->depotIdToReject);
        if ($depot === null) {
            abort(404);
        }

        $this->authorize('treat', $depot);

        try {
            app(FacturePartenaireService::class)->rejeter($depot, $this->motifRejet);
        } catch (\DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('success', 'Le dépôt a été rejeté.');
        $this->fermerRejet();
    }

    /** @return Collection<int, FacturePartenaireDeposee> */
    private function queryDepots(): Collection
    {
        $query = FacturePartenaireDeposee::with(['tiers'])
            ->orderByDesc('date_facture');

        return match ($this->onglet) {
            'traitees' => $query->where('statut', StatutFactureDeposee::Traitee->value)->get(),
            'rejetees' => $query->where('statut', StatutFactureDeposee::Rejetee->value)->get(),
            'toutes' => $query->whereIn('statut', [
                StatutFactureDeposee::Soumise->value,
                StatutFactureDeposee::Traitee->value,
                StatutFactureDeposee::Rejetee->value,
            ])->get(),
            default => $query->where('statut', StatutFactureDeposee::Soumise->value)->get(),
        };
    }
}
