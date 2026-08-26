<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\Rapports\BilanComptableBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportBilan extends Component
{
    #[Url(as: 'n1')]
    public bool $compareN1 = true;

    public function exportUrl(string $format): string
    {
        return route('rapports.export', [
            'rapport' => 'bilan',
            'format' => $format,
            'exercice' => app(ExerciceService::class)->current(),
            'n1' => $this->compareN1 ? '1' : '0',
        ]);
    }

    public function formatCentimes(int $centimes): string
    {
        if ($centimes === 0) {
            return '—';
        }

        return number_format($centimes / 100, 2, ',', ' ').' €';
    }

    public function render(BilanComptableBuilder $builder): View
    {
        return view('livewire.rapport-bilan', [
            'bilan' => $builder->build(app(ExerciceService::class)->current()),
        ]);
    }
}
