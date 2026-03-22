<?php

// app/Livewire/TiersList.php
declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\WithPerPage;
use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class TiersList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public string $filtre = ''; // '', 'depenses', 'recettes'

    public bool $filtreHelloasso = false;

    public string $sortBy = 'nom';

    public string $sortDir = 'asc';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFiltre(): void
    {
        $this->resetPage();
    }

    public function updatedFiltreHelloasso(): void
    {
        $this->resetPage();
    }

    public function sort(string $col): void
    {
        $allowed = ['nom', 'ville', 'email'];
        if (! in_array($col, $allowed, true)) {
            return;
        }
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    #[On('tiers-saved')]
    public function refresh(): void {}

    public function requestEdit(int $id): void
    {
        $this->dispatch('edit-tiers', id: $id);
    }

    public function delete(int $id): void
    {
        $tiers = Tiers::findOrFail($id);
        try {
            app(TiersService::class)->delete($tiers);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $query = Tiers::query();

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom', 'like', "%{$this->search}%")
                    ->orWhere('prenom', 'like', "%{$this->search}%")
                    ->orWhere('entreprise', 'like', "%{$this->search}%")
                    ->orWhere('ville', 'like', "%{$this->search}%")
                    ->orWhere('code_postal', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if ($this->filtre === 'depenses') {
            $query->where('pour_depenses', true);
        } elseif ($this->filtre === 'recettes') {
            $query->where('pour_recettes', true);
        }

        if ($this->filtreHelloasso) {
            $query->where('est_helloasso', true);
        }

        $dir = $this->sortDir === 'desc' ? 'desc' : 'asc';
        if ($this->sortBy === 'nom') {
            $query->orderByRaw('COALESCE(entreprise, nom) '.$dir);
        } else {
            $query->orderBy($this->sortBy, $dir);
        }

        return view('livewire.tiers-list', [
            'tiersList' => $query->paginate($this->effectivePerPage()),
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
        ]);
    }
}
