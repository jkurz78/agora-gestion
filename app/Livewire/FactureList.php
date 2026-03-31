<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\StatutFacture;
use App\Models\Facture;
use App\Models\Tiers;
use App\Services\ExerciceService;
use App\Services\FactureService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class FactureList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $filterStatut = '';

    public string $filterTiers = '';

    public ?int $newFactureTiersId = null;

    public function updatedFilterStatut(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTiers(): void
    {
        $this->resetPage();
    }

    public function creer(): void
    {
        $this->validate([
            'newFactureTiersId' => ['required', 'exists:tiers,id'],
        ]);

        $facture = app(FactureService::class)->creer($this->newFactureTiersId);

        $this->redirect(route('gestion.factures.edit', $facture));
    }

    public function supprimer(int $id): void
    {
        try {
            $facture = Facture::findOrFail($id);
            app(FactureService::class)->supprimerBrouillon($facture);
            session()->flash('success', 'Brouillon supprime.');
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Facture::with('tiers')
            ->where('exercice', $exercice);

        if ($this->filterStatut !== '') {
            if ($this->filterStatut === 'acquittee') {
                // Acquittee = validee + fully paid — filter in collection after query
                $query->where('statut', StatutFacture::Validee);
            } else {
                $query->where('statut', $this->filterStatut);
            }
        }

        if ($this->filterTiers !== '') {
            $search = $this->filterTiers;
            $query->whereHas('tiers', function ($q) use ($search): void {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%")
                    ->orWhere('entreprise', 'like', "%{$search}%");
            });
        }

        $query->orderByDesc('date')->orderByDesc('id');

        $factures = $query->paginate(20);

        // Post-filter for acquittee: only keep factures that are truly acquittee
        // (This is handled in the view via isAcquittee() for display)

        $tiers = Tiers::where('pour_recettes', true)
            ->orderBy('nom')
            ->get();

        return view('livewire.facture-list', [
            'factures' => $factures,
            'tiers' => $tiers,
        ]);
    }
}
