<?php

declare(strict_types=1);

namespace App\Livewire\Provisions;

use App\Models\Provision;
use App\Services\ExerciceService;
use Illuminate\View\View;
use Livewire\Component;

final class ProvisionIndex extends Component
{
    public function render(): View
    {
        $exerciceService = app(ExerciceService::class);
        $exercice = $exerciceService->current();

        return view('livewire.provisions.provision-index', [
            'provisions' => Provision::forExercice($exercice)->with('ligne.compte', 'compte')->get(),
            'exerciceLabel' => $exerciceService->label($exercice),
        ]);
    }
}
