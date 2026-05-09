<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Attributes\On;
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

    #[On('adhesion-creee')]
    public function onAdhesionCreee(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Tiers::query();

        match ($this->filtre) {
            'a_jour' => $query->whereHas('adhesions', fn ($q) => $q->where('exercice', $exercice)),
            'en_retard' => $query
                ->whereHas('adhesions', fn ($q) => $q->where('exercice', $exercice - 1))
                ->whereDoesntHave('adhesions', fn ($q) => $q->where('exercice', $exercice)),
            default => $query->whereHas('adhesions'),
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%'.$this->search.'%')
                    ->orWhere('prenom', 'like', '%'.$this->search.'%');
            });
        }

        $membres = $query->orderBy('nom')->paginate($this->effectivePerPage());

        $membres->getCollection()->each(function (Tiers $tiers): void {
            $derniereAdhesion = $tiers->adhesions()
                ->with('transaction.compte')
                ->orderByDesc('exercice')
                ->orderByDesc('id')
                ->first();
            $tiers->setAttribute('derniereAdhesion', $derniereAdhesion);
        });

        return view('livewire.adherent-list', compact('membres'));
    }
}
