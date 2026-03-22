<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\Cotisation;
use App\Models\SousCategorie;
use App\Services\CotisationService;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class CotisationList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $tiers_search = '';

    public ?int $sous_categorie_id = null;

    public function updatedTiersSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSousCategorieId(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $cotisation = Cotisation::findOrFail($id);

        try {
            app(CotisationService::class)->delete($cotisation);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[On('cotisation-saved')]
    public function onCotisationSaved(): void {}

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Cotisation::with(['tiers', 'compte', 'sousCategorie'])
            ->where('exercice', $exercice)
            ->latest('date_paiement')
            ->latest('id');

        if ($this->tiers_search !== '') {
            $search = $this->tiers_search;
            $query->whereHas('tiers', function ($q) use ($search): void {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%");
            });
        }

        if ($this->sous_categorie_id) {
            $query->where('sous_categorie_id', $this->sous_categorie_id);
        }

        return view('livewire.cotisation-list', [
            'cotisations' => $query->paginate($this->effectivePerPage()),
            'postescotisation' => SousCategorie::where('pour_cotisations', true)->orderBy('nom')->get(),
        ]);
    }
}
