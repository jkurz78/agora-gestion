<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\TypeCategorie;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\Depense;
use App\Models\Operation;
use App\Services\DepenseService;
use App\Services\ExerciceService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Livewire\Concerns\WithPerPage;
use Livewire\WithPagination;

final class DepenseList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public ?int $categorie_id = null;

    public ?int $sous_categorie_id = null;

    public ?int $operation_id = null;

    public ?int $compte_id = null;

    public ?string $pointe = null;

    public ?string $tiers = null;

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
        $depense = Depense::findOrFail($id);
        try {
            app(DepenseService::class)->delete($depense);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[On('depense-saved')]
    public function onDepenseSaved(): void
    {
        // Livewire will re-render automatically
    }

    #[On('csv-imported')]
    public function refreshList(): void
    {
        // Livewire re-renders automatically after this method runs
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Depense::with(['lignes.sousCategorie.categorie', 'compte', 'saisiPar'])
            ->forExercice($exercice)
            ->latest('date')
            ->latest('id');

        if ($this->categorie_id) {
            $query->whereHas('lignes.sousCategorie', function ($q) {
                $q->where('categorie_id', $this->categorie_id);
            });
        }

        if ($this->sous_categorie_id) {
            $query->whereHas('lignes', function ($q) {
                $q->where('sous_categorie_id', $this->sous_categorie_id);
            });
        }

        if ($this->operation_id) {
            $opId = $this->operation_id;
            $query->whereHas('lignes', function ($q) use ($opId): void {
                $q->where(function ($inner) use ($opId): void {
                    // Ligne sans affectation : operation_id direct
                    $inner->where('operation_id', $opId)
                        ->whereDoesntHave('affectations');
                })->orWhereHas('affectations', function ($qa) use ($opId): void {
                    $qa->where('operation_id', $opId);
                });
            });
        }

        if ($this->compte_id) {
            $query->where('compte_id', $this->compte_id);
        }

        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }

        if ($this->tiers) {
            $tiersSearch = $this->tiers;
            $query->whereHas('tiers', function ($q) use ($tiersSearch): void {
                $q->whereRaw("TRIM(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''))) LIKE ?", ["%{$tiersSearch}%"]);
            });
        }

        return view('livewire.depense-list', [
            'depenses' => $query->paginate($this->effectivePerPage()),
            'categories' => Categorie::where('type', TypeCategorie::Depense)->orderBy('nom')->get(),
            'operations' => Operation::orderBy('nom')->get(),
            'comptes' => CompteBancaire::orderBy('nom')->get(),
        ]);
    }
}
