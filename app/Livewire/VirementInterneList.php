<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class VirementInterneList extends Component
{
    use WithPagination;

    protected string $paginationTheme = 'bootstrap';

    public ?int $exercice = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function updatedExercice(): void
    {
        $this->resetPage();
    }

    #[On('virement-saved')]
    public function refresh(): void {}

    public function delete(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        app(VirementInterneService::class)->delete($virement);
    }

    public function render(): \Illuminate\View\View
    {
        $virements = VirementInterne::with(['compteSource', 'compteDestination', 'saisiPar'])
            ->when($this->exercice, fn ($q) => $q->forExercice($this->exercice))
            ->orderByDesc('date')
            ->paginate(20);

        return view('livewire.virement-interne-list', [
            'virements' => $virements,
        ]);
    }
}
