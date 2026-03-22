<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class MembreList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $filtre = 'a_jour';

    public string $search = '';

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();
        $cotSousCategorieIds = SousCategorie::where('pour_cotisations', true)->pluck('id');

        $query = Tiers::query();

        // "cotisations" scope: tiers having transaction_lignes with pour_cotisations sous-categories
        $hasCotisation = fn ($q, ?int $ex = null) => $q->whereHas('transactions', function ($tq) use ($cotSousCategorieIds, $ex) {
            $tq->whereHas('lignes', function ($lq) use ($cotSousCategorieIds, $ex) {
                $lq->whereIn('sous_categorie_id', $cotSousCategorieIds);
                if ($ex !== null) {
                    $lq->where('exercice', $ex);
                }
            });
        });

        match ($this->filtre) {
            'a_jour' => $hasCotisation($query, $exercice),
            'en_retard' => $hasCotisation($query, $exercice - 1)
                ->whereDoesntHave('transactions', function ($tq) use ($cotSousCategorieIds, $exercice) {
                    $tq->whereHas('lignes', function ($lq) use ($cotSousCategorieIds, $exercice) {
                        $lq->whereIn('sous_categorie_id', $cotSousCategorieIds)
                            ->where('exercice', $exercice);
                    });
                }),
            default => $hasCotisation($query),
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%');
            });
        }

        $membres = $query->orderBy('nom')->paginate($this->effectivePerPage());

        // Eager-load dernière cotisation par tiers (via transaction_lignes)
        $membres->getCollection()->each(function (Tiers $tiers) use ($cotSousCategorieIds): void {
            $derniereLigne = TransactionLigne::whereIn('sous_categorie_id', $cotSousCategorieIds)
                ->whereHas('transaction', fn ($q) => $q->where('tiers_id', $tiers->id))
                ->with('transaction.compte')
                ->orderByDesc('exercice')
                ->first();

            $tiers->setAttribute('derniereCotisation', $derniereLigne);
        });

        return view('livewire.membre-list', compact('membres'));
    }
}
