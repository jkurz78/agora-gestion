<?php

declare(strict_types=1);

namespace App\Livewire\DevisLibre;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Models\Tiers;
use App\Services\DevisService;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Component;
use Livewire\WithPagination;

final class DevisList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $filtreStatut = '';

    public ?int $filtreTiersId = null;

    public ?int $filtreExercice = null;

    public string $search = '';

    /** Whether the "choose a tiers" modal is open. */
    public bool $showCreerModal = false;

    /** tiers_id chosen in the modal before creation. */
    public ?int $nouveauTiersId = null;

    public function mount(): void
    {
        if ($this->filtreExercice === null) {
            $this->filtreExercice = app(ExerciceService::class)->current();
        }
    }

    public function updatedFiltreStatut(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreTiersId(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreExercice(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Returns true when the devis is envoyé and its date_validite is in the past.
     */
    public function expire(Devis $devis): bool
    {
        return $devis->statut === StatutDevis::Envoye
            && $devis->date_validite->lt(today());
    }

    /**
     * Create a new devis for the given tiers and redirect to its edit page.
     *
     * If no tiers_id is provided, open the tiers-selection modal instead.
     */
    public function creerDevis(?int $tiersId = null): mixed
    {
        if ($tiersId === null) {
            $this->showCreerModal = true;

            return null;
        }

        $devis = app(DevisService::class)->creer($tiersId);

        return $this->redirect(route('devis-libres.show', $devis));
    }

    public function render(): View
    {
        $query = Devis::with('tiers')
            ->orderByDesc('date_emission')
            ->orderByDesc('id');

        // Filtre exercice
        if ($this->filtreExercice !== null) {
            $query->where('exercice', $this->filtreExercice);
        }

        // Filtre statut
        if ($this->filtreStatut === '') {
            // Défaut : tout sauf annulé
            $query->where('statut', '!=', StatutDevis::Annule->value);
        } else {
            $query->where('statut', $this->filtreStatut);
        }

        // Filtre tiers
        if ($this->filtreTiersId !== null) {
            $query->where('tiers_id', $this->filtreTiersId);
        }

        // Recherche (numéro ou libellé)
        if ($this->search !== '') {
            $search = $this->search;
            $query->where(function ($q) use ($search): void {
                $q->where('numero', 'like', "%{$search}%")
                    ->orWhere('libelle', 'like', "%{$search}%");
            });
        }

        /** @var LengthAwarePaginator $devis */
        $devis = $query->paginate(50);

        $tiers = Tiers::orderBy('nom')->get();

        return view('livewire.devis-libre.devis-list', [
            'devis' => $devis,
            'tiers' => $tiers,
        ])->layout('layouts.app-sidebar', ['title' => 'Devis libres']);
    }
}
