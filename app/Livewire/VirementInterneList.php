<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Livewire\Attributes\On;
use Livewire\Component;

final class VirementInterneList extends Component
{
    public ?int $exercice = null;

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    #[On('virement-saved')]
    public function refresh(): void {}

    public function delete(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        app(VirementInterneService::class)->delete($virement);
    }

    public function render()
    {
        $virements = VirementInterne::with(['compteSource', 'compteDestination', 'saisiPar'])
            ->when($this->exercice, fn ($q) => $q->forExercice($this->exercice))
            ->orderByDesc('date')
            ->get();

        return view('livewire.virement-interne-list', [
            'virements' => $virements,
        ]);
    }
}
