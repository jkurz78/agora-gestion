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

    public ?int $exercice = null;
    public string $donateur_search = '';
    public ?int $operation_id = null;
    public ?int $showDonateurId = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function updatedExercice(): void
    {
        $this->resetPage();
    }

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
        app(DonService::class)->delete($don);
    }

    #[On('don-saved')]
    public function onDonSaved(): void
    {
        // Livewire will re-render automatically
    }

    public function render()
    {
        $query = Don::with(['donateur', 'operation', 'compte'])
            ->latest('date')
            ->latest('id');

        if ($this->exercice) {
            $query->forExercice($this->exercice);
        }

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

        $exerciceService = app(ExerciceService::class);

        return view('livewire.don-list', [
            'dons' => $query->paginate(15),
            'operations' => Operation::orderBy('nom')->get(),
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
            'donateurDons' => $donateurDons,
        ]);
    }
}
