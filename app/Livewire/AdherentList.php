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

        $today = now()->toDateString();
        $days30Ago = now()->subDays(30)->toDateString();

        match ($this->filtre) {
            'a_jour' => $query->whereHas('adhesions', function ($q) use ($exercice, $today): void {
                $q->where(function ($s) use ($exercice, $today): void {
                    $s->where('exercice', $exercice)
                        ->orWhere(function ($d) use ($today): void {
                            $d->whereNotNull('date_debut')
                                ->whereNotNull('date_fin')
                                ->whereDate('date_debut', '<=', $today)
                                ->whereDate('date_fin', '>=', $today);
                        })
                        ->orWhere('mode', 'illimite');
                });
            }),
            'en_retard' => $query
                ->whereHas('adhesions', function ($q) use ($exercice, $days30Ago, $today): void {
                    $q->where(function ($s) use ($exercice, $days30Ago, $today): void {
                        $s->where('exercice', $exercice - 1)
                            ->orWhere(function ($d) use ($days30Ago, $today): void {
                                $d->whereNotNull('date_fin')
                                    ->whereDate('date_fin', '>=', $days30Ago)
                                    ->whereDate('date_fin', '<', $today);
                            });
                    });
                })
                ->whereDoesntHave('adhesions', function ($q) use ($exercice, $today): void {
                    $q->where(function ($s) use ($exercice, $today): void {
                        $s->where('exercice', $exercice)
                            ->orWhere(function ($d) use ($today): void {
                                $d->whereNotNull('date_debut')
                                    ->whereNotNull('date_fin')
                                    ->whereDate('date_debut', '<=', $today)
                                    ->whereDate('date_fin', '>=', $today);
                            })
                            ->orWhere('mode', 'illimite');
                    });
                }),
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
                ->with(['transaction.compte', 'formuleAdhesion'])
                ->orderByDesc('exercice')
                ->orderByDesc('date_fin')
                ->orderByDesc('id')
                ->first();
            $tiers->setAttribute('derniereAdhesion', $derniereAdhesion);
        });

        return view('livewire.adherent-list', compact('membres'));
    }
}
