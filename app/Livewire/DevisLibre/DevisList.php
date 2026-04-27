<?php

declare(strict_types=1);

namespace App\Livewire\DevisLibre;

use App\Enums\StatutDevis;
use App\Models\Devis;
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

    public function creerDevis(): void
    {
        $this->dispatch('creer-devis');
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

        return view('livewire.devis-libre.devis-list', [
            'devis' => $devis,
        ]);
    }
}
