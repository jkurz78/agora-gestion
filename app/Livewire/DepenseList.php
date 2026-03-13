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
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class DepenseList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public ?int $exercice = null;

    public ?int $categorie_id = null;

    public ?int $sous_categorie_id = null;

    public ?int $operation_id = null;

    public ?int $compte_id = null;

    public ?string $pointe = null;

    public ?string $beneficiaire = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function updatedExercice(): void
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

    public function updatedBeneficiaire(): void
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

    public function render(): \Illuminate\Contracts\View\View
    {
        $query = Depense::with(['lignes.sousCategorie.categorie', 'compte', 'saisiPar'])
            ->latest('date')
            ->latest('id');

        if ($this->exercice) {
            $query->forExercice($this->exercice);
        }

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
            $query->whereHas('lignes', function ($q) {
                $q->where('operation_id', $this->operation_id);
            });
        }

        if ($this->compte_id) {
            $query->where('compte_id', $this->compte_id);
        }

        if ($this->pointe !== null && $this->pointe !== '') {
            $query->where('pointe', $this->pointe === '1');
        }

        if ($this->beneficiaire) {
            $query->where('beneficiaire', 'like', '%'.$this->beneficiaire.'%');
        }

        $exerciceService = app(ExerciceService::class);

        return view('livewire.depense-list', [
            'depenses' => $query->paginate(15),
            'categories' => Categorie::where('type', TypeCategorie::Depense)->orderBy('nom')->get(),
            'operations' => Operation::orderBy('nom')->get(),
            'comptes' => CompteBancaire::orderBy('nom')->get(),
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
        ]);
    }
}
