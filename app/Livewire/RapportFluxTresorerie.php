<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportFluxTresorerie extends Component
{
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'flux-tresorerie',
            'format' => $format,
            'exercice' => $exercice,
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->fluxTresorerie($exercice);

        return view('livewire.rapport-flux-tresorerie', $data);
    }
}
