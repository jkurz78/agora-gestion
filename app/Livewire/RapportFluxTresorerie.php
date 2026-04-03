<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportFluxTresorerie extends Component
{
    #[Url(as: 'mensuel')]
    public bool $fluxMensuels = false;

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->fluxTresorerie($exercice);

        return view('livewire.rapport-flux-tresorerie', $data);
    }
}
