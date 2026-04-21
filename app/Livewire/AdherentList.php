<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UsageComptable;
use App\Livewire\Concerns\WithPerPage;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class AdherentList extends Component
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
        $cotSousCategorieIds = SousCategorie::forUsage(UsageComptable::Cotisation)->pluck('id');

        $query = Tiers::query();

        // "cotisations" scope: tiers having transaction_lignes with pour_cotisations sous-categories
        $hasCotisation = fn ($q, ?int $ex = null) => $q->whereHas('transactions', function ($tq) use ($cotSousCategorieIds, $ex) {
            if ($ex !== null) {
                $tq->forExercice($ex);
            }
            $tq->whereHas('lignes', function ($lq) use ($cotSousCategorieIds) {
                $lq->whereIn('sous_categorie_id', $cotSousCategorieIds);
            });
        });

        match ($this->filtre) {
            'a_jour' => $hasCotisation($query, $exercice),
            'en_retard' => $hasCotisation($query, $exercice - 1)
                ->whereDoesntHave('transactions', function ($tq) use ($cotSousCategorieIds, $exercice) {
                    $tq->forExercice($exercice)
                        ->whereHas('lignes', function ($lq) use ($cotSousCategorieIds) {
                            $lq->whereIn('sous_categorie_id', $cotSousCategorieIds);
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
                ->orderByDesc(
                    Transaction::select('date')
                        ->whereColumn('transactions.id', 'transaction_lignes.transaction_id')
                        ->limit(1)
                )
                ->first();

            $tiers->setAttribute('derniereCotisation', $derniereLigne);
        });

        return view('livewire.adherent-list', compact('membres'));
    }
}
