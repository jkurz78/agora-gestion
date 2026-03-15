<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Tiers;
use App\Services\TiersTransactionService;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class TiersTransactions extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public int $tiersId;

    public string $typeFilter = '';
    public string $dateDebut  = '';
    public string $dateFin    = '';
    public string $search     = '';
    public string $sortBy     = 'date';
    public string $sortDir    = 'desc';

    public function sort(string $col): void
    {
        if ($this->sortBy === $col) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy  = $col;
            $this->sortDir = 'asc';
        }
        $this->resetPage();
    }

    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedDateDebut(): void  { $this->resetPage(); }
    public function updatedDateFin(): void    { $this->resetPage(); }
    public function updatedSearch(): void     { $this->resetPage(); }

    public function render(): View
    {
        $tiers = Tiers::findOrFail($this->tiersId);

        $transactions = app(TiersTransactionService::class)->paginate(
            $tiers,
            $this->typeFilter,
            $this->dateDebut,
            $this->dateFin,
            $this->search,
            $this->sortBy,
            $this->sortDir,
        );

        return view('livewire.tiers-transactions', compact('tiers', 'transactions'));
    }
}
