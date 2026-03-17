<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;
use App\Livewire\Concerns\WithPerPage;
use Livewire\WithPagination;

final class MembreList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $filtre = 'a_jour';
    public string $search = '';

    public function updatedFiltre(): void { $this->resetPage(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Tiers::query();

        match ($this->filtre) {
            'a_jour' => $query->whereHas(
                'cotisations',
                fn ($q) => $q->forExercice($exercice)
            ),
            'en_retard' => $query
                ->whereHas('cotisations', fn ($q) => $q->forExercice($exercice - 1))
                ->whereDoesntHave('cotisations', fn ($q) => $q->forExercice($exercice)),
            default => $query->whereHas('cotisations'),
        };

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', '%' . $this->search . '%')
                    ->orWhere('prenom', 'like', '%' . $this->search . '%');
            });
        }

        $membres = $query->orderBy('nom')->paginate($this->effectivePerPage());

        // Eager-load dernière cotisation par tiers
        $membres->getCollection()->each(function (Tiers $tiers): void {
            $tiers->setRelation(
                'derniereCotisation',
                $tiers->cotisations()->latest('date_paiement')->first()
            );
        });

        return view('livewire.membre-list', compact('membres'));
    }
}
