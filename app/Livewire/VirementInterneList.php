<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Livewire\Concerns\RespectsExerciceCloture;
use App\Livewire\Concerns\WithPerPage;
use App\Models\VirementInterne;
use App\Services\ExerciceService;
use App\Services\VirementInterneService;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

final class VirementInterneList extends Component
{
    use RespectsExerciceCloture;
    use WithPagination;
    use WithPerPage;

    protected string $paginationTheme = 'bootstrap';

    #[On('virement-saved')]
    public function refresh(): void {}

    public function delete(int $id): void
    {
        $virement = VirementInterne::findOrFail($id);
        try {
            app(VirementInterneService::class)->delete($virement);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render(): View
    {
        $exercice = app(ExerciceService::class)->current();

        $virements = VirementInterne::with(['compteSource', 'compteDestination', 'saisiPar'])
            ->forExercice($exercice)
            ->orderByDesc('date')
            ->paginate($this->effectivePerPage());

        return view('livewire.virement-interne-list', [
            'virements' => $virements,
        ]);
    }
}
