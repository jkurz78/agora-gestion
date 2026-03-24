<?php

declare(strict_types=1);

namespace App\Livewire\Exercices;

use App\Models\ExerciceAction;
use Illuminate\View\View;
use Livewire\Component;

final class PisteAudit extends Component
{
    public function render(): View
    {
        $actions = ExerciceAction::with(['exercice', 'user'])
            ->latest('created_at')
            ->get();

        return view('livewire.exercices.piste-audit', [
            'actions' => $actions,
        ]);
    }
}
