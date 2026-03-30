<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeTransaction;
use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Livewire\Concerns\WithPerPage;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Transaction;
use App\Services\ExerciceService;
use App\Services\TransactionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class TransactionList extends Component
{
    use RespectsExerciceCloture;
    use WithPagination, WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    #[Url(as: 'type')]
    public string $typeFilter = ''; // '' | 'depense' | 'recette'

    public ?int $categorie_id = null;

    public ?int $sous_categorie_id = null;

    public ?int $operation_id = null;

    public ?int $compte_id = null;

    public ?string $pointe = null;

    public ?string $tiers = null;

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategorieId(): void
    {
        $this->sous_categorie_id = null;
        $this->resetPage();
    }

    public function updatedSousCategorieId(): void
    {
        $this->resetPage();
    }

    public function updatedOperationId(): void
    {
        $this->resetPage();
    }

    public function updatedCompteId(): void
    {
        $this->resetPage();
    }

    public function updatedPointe(): void
    {
        $this->resetPage();
    }

    public function updatedTiers(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $transaction = Transaction::findOrFail($id);
        try {
            app(TransactionService::class)->delete($transaction);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[On('transaction-saved')]
    public function onTransactionSaved(): void {}

    #[On('csv-imported')]
    public function refreshList(): void {}

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Transaction::with(['lignes.sousCategorie.categorie', 'compte', 'saisiPar'])
            ->forExercice($exercice)
            ->latest('date')->latest('id');

        if ($this->typeFilter !== '') {
            $query->where('type', $this->typeFilter);
        }

        if ($this->categorie_id) {
            $query->whereHas('lignes.sousCategorie', fn ($q) => $q->where('categorie_id', $this->categorie_id));
        }
        if ($this->sous_categorie_id) {
            $query->whereHas('lignes', fn ($q) => $q->where('sous_categorie_id', $this->sous_categorie_id));
        }
        if ($this->operation_id) {
            $opId = $this->operation_id;
            $query->whereHas('lignes', fn ($q) => $q
                ->where(fn ($inner) => $inner->where('operation_id', $opId)->whereDoesntHave('affectations'))
                ->orWhereHas('affectations', fn ($qa) => $qa->where('operation_id', $opId))
            );
        }
        if ($this->compte_id) {
            $query->where('compte_id', $this->compte_id);
        }
        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }
        if ($this->tiers) {
            $tiersSearch = $this->tiers;
            $query->whereHas('tiers', fn ($q) => $q->whereRaw(
                "TRIM(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''))) LIKE ?", ["%{$tiersSearch}%"]
            ));
        }

        // Catégories filtrées selon le typeFilter
        $typeCategorie = $this->typeFilter !== '' ? $this->typeFilter : null;
        $categories = Categorie::when($typeCategorie, fn ($q) => $q->where('type', $typeCategorie))
            ->orderBy('nom')->get();

        return view('livewire.transaction-list', [
            'transactions' => $query->paginate($this->effectivePerPage()),
            'categories' => $categories,
            'operations' => Operation::with('typeOperation')->orderBy('nom')->get(),
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'typeLabels' => TypeTransaction::cases(),
        ]);
    }
}
