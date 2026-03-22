<?php

// app/Livewire/TiersList.php
declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Livewire\Concerns\WithPerPage;
use Livewire\WithPagination;

final class TiersList extends Component
{
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    public string $search = '';

    public string $filtre = ''; // '', 'depenses', 'recettes'

    public bool $filtreHelloasso = false;

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
        $query = Tiers::orderBy('nom');

        if ($this->search !== '') {
            $query->where(function ($q): void {
                $q->where('nom',          'like', "%{$this->search}%")
                  ->orWhere('prenom',     'like', "%{$this->search}%")
                  ->orWhere('entreprise', 'like', "%{$this->search}%")
                  ->orWhere('ville',      'like', "%{$this->search}%")
                  ->orWhere('code_postal','like', "%{$this->search}%")
                  ->orWhere('email',      'like', "%{$this->search}%");
            });
        }

        if ($this->filtre === 'depenses') {
            $query->where('pour_depenses', true);
        } elseif ($this->filtre === 'recettes') {
            $query->where('pour_recettes', true);
        }

        if ($this->filtreHelloasso) {
            $query->whereNotNull('helloasso_id');
        }

        return view('livewire.tiers-list', [
            'tiersList' => $query->paginate($this->effectivePerPage()),
        ]);
    }
}
