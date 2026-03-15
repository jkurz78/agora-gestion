<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Don;
use App\Models\Operation;
use App\Services\DonService;
use App\Services\ExerciceService;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class DonList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public string $tiers_search = '';

    public ?int $operation_id = null;

    public ?int $showTiersId = null;

    public function updatedTiersSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOperationId(): void
    {
        $this->resetPage();
    }

    public function toggleTiersHistory(int $tiersId): void
    {
        $this->showTiersId = $this->showTiersId === $tiersId ? null : $tiersId;
    }

    public function delete(int $id): void
    {
        $don = Don::findOrFail($id);
        try {
            app(DonService::class)->delete($don);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    #[On('don-saved')]
    public function onDonSaved(): void
    {
        // Livewire will re-render automatically
    }

    public function render()
    {
        $exercice = app(ExerciceService::class)->current();

        $query = Don::with(['tiers', 'operation', 'compte'])
            ->forExercice($exercice)
            ->latest('date')
            ->latest('id');

        if ($this->tiers_search !== '') {
            $search = $this->tiers_search;
            $query->whereHas('tiers', function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%");
            });
        }

        if ($this->operation_id) {
            $query->where('operation_id', $this->operation_id);
        }

        $tiersDons = collect();
        if ($this->showTiersId) {
            $tiersDons = Don::where('tiers_id', $this->showTiersId)
                ->latest('date')
                ->get();
        }

        return view('livewire.don-list', [
            'dons' => $query->paginate(15),
            'operations' => Operation::orderBy('nom')->get(),
            'tiersDons' => $tiersDons,
        ]);
    }
}
