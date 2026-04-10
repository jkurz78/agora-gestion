<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\Espace;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Services\RemiseBancaireService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class RemiseBancaireSelection extends Component
{
    public RemiseBancaire $remise;

    /** @var list<int> */
    public array $selectedIds = [];

    public string $filterOperation = '';

    public string $filterSeance = '';

    public string $filterTiers = '';

    public function mount(RemiseBancaire $remise): void
    {
        $this->remise = $remise;

        // Pre-select already-linked reglements (for modification flow)
        $this->selectedIds = Reglement::where('remise_id', $remise->id)
            ->pluck('id')
            ->toArray();
    }

    public function getCanEditProperty(): bool
    {
        return Auth::user()->role->canWrite(Espace::Gestion);
    }

    public function updatedFilterOperation(): void
    {
        $this->filterSeance = '';
    }

    public function toggleAll(array $visibleIds): void
    {
        if (! $this->canEdit) {
            return;
        }

        $allSelected = count(array_intersect($visibleIds, $this->selectedIds)) === count($visibleIds);

        if ($allSelected) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, $visibleIds));
        } else {
            $this->selectedIds = array_values(array_unique(array_merge($this->selectedIds, $visibleIds)));
        }
    }

    public function toggleReglement(int $id): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    public function valider(): void
    {
        if (! $this->canEdit) {
            return;
        }

        if (count($this->selectedIds) === 0) {
            $this->addError('selection', 'Sélectionnez au moins un règlement.');

            return;
        }

        app(RemiseBancaireService::class)->enregistrerBrouillon($this->remise, $this->selectedIds);

        session(['remise_selected_ids' => $this->selectedIds]);
        $this->redirect(route('compta.banques.remises.validation', $this->remise));
    }

    public function render(): View
    {
        // Base : tous les règlements éligibles (pour les listes de filtres)
        $baseQuery = Reglement::with(['participant.tiers', 'seance.operation'])
            ->where('mode_paiement', $this->remise->mode_paiement->value)
            ->where('montant_prevu', '>', 0)
            ->where(function ($q) {
                $q->whereNull('remise_id')
                    ->orWhere('remise_id', $this->remise->id);
            });

        $allReglements = $baseQuery->get();

        // Options de filtre : opérations toujours complètes
        $operations = $allReglements->map(fn ($r) => $r->seance->operation)->unique('id')->sortBy('nom')->values();

        // Séances : filtrées par opération sélectionnée uniquement
        $seancesSource = $this->filterOperation !== ''
            ? $allReglements->filter(fn ($r) => (string) $r->seance->operation->id === $this->filterOperation)
            : $allReglements;
        $seances = $seancesSource->map(fn ($r) => $r->seance)->unique('id')->sortBy('numero')->values();

        // Résultats filtrés
        $reglements = $allReglements;

        if ($this->filterOperation !== '') {
            $reglements = $reglements->filter(fn ($r) => (string) $r->seance->operation->id === $this->filterOperation);
        }

        if ($this->filterSeance !== '') {
            $reglements = $reglements->filter(fn ($r) => (string) $r->seance_id === $this->filterSeance);
        }

        if ($this->filterTiers !== '') {
            $search = mb_strtolower($this->filterTiers);
            $reglements = $reglements->filter(fn ($r) => str_contains(mb_strtolower($r->participant->tiers->nom ?? ''), $search)
                || str_contains(mb_strtolower($r->participant->tiers->prenom ?? ''), $search)
            );
        }

        $reglements = $reglements->sortBy(fn ($r) => [
            $r->seance->operation->nom ?? '',
            $r->seance->numero ?? 0,
            $r->participant->tiers->nom ?? '',
        ])->values();

        $totalSelected = $reglements->whereIn('id', $this->selectedIds)->sum('montant_prevu');
        $countSelected = count($this->selectedIds);

        return view('livewire.remise-bancaire-selection', [
            'reglements' => $reglements,
            'totalSelected' => $totalSelected,
            'countSelected' => $countSelected,
            'operations' => $operations,
            'seances' => $seances,
        ]);
    }
}
