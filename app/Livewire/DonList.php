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

    public string $donateur_search = '';

    public ?int $operation_id = null;

    public ?int $showDonateurId = null;

    public function updatedDonateurSearch(): void
    {
        $this->resetPage();
    }

    public function updatedOperationId(): void
    {
        $this->resetPage();
    }

    public function toggleDonateurHistory(int $donateurId): void
    {
        $this->showDonateurId = $this->showDonateurId === $donateurId ? null : $donateurId;
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

        $query = Don::with(['donateur', 'operation', 'compte'])
            ->forExercice($exercice)
            ->latest('date')
            ->latest('id');

        if ($this->donateur_search !== '') {
            $search = $this->donateur_search;
            $query->whereHas('donateur', function ($q) use ($search) {
                $q->where('nom', 'like', "%{$search}%")
                    ->orWhere('prenom', 'like', "%{$search}%");
            });
        }

        if ($this->operation_id) {
            $query->where('operation_id', $this->operation_id);
        }

        $donateurDons = collect();
        if ($this->showDonateurId) {
            $donateurDons = Don::where('donateur_id', $this->showDonateurId)
                ->latest('date')
                ->get();
        }

        return view('livewire.don-list', [
            'dons' => $query->paginate(15),
            'operations' => Operation::orderBy('nom')->get(),
            'donateurDons' => $donateurDons,
        ]);
    }
}
