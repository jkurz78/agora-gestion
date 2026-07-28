<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\Rapports\GrandLivreBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportGrandLivre extends Component
{
    #[Url(as: 'du')]
    public string $dateDebut = '';

    #[Url(as: 'au')]
    public string $dateFin = '';

    #[Url(as: 'comptes')]
    public string $comptes = '1,2,3,4,5,6,7';

    #[Url(as: 'non_soldes')]
    public bool $uniquementNonSoldes = false;

    public function mount(ExerciceService $exerciceService): void
    {
        $range = $exerciceService->dateRange($exerciceService->current());

        if ($this->dateDebut === '') {
            $this->dateDebut = $range['start']->toDateString();
        }

        if ($this->dateFin === '') {
            $this->dateFin = $range['end']->toDateString();
        }
    }

    /**
     * @return array<int, string>
     */
    public function prefixesComptes(): array
    {
        return collect(preg_split('/[,\s;]+/', $this->comptes) ?: [])
            ->map(fn (string $prefixe): string => trim($prefixe))
            ->filter(fn (string $prefixe): bool => $prefixe !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function formatCentimes(int $centimes): string
    {
        if ($centimes === 0) {
            return '—';
        }

        $prefixe = $centimes < 0 ? '-' : '';

        return $prefixe.number_format(abs($centimes) / 100, 2, ',', ' ').' €';
    }

    public function render(GrandLivreBuilder $builder): View
    {
        return view('livewire.rapport-grand-livre', [
            'grandLivre' => $builder->grandLivre(
                $this->dateDebut,
                $this->dateFin,
                $this->prefixesComptes(),
                $this->uniquementNonSoldes,
            ),
        ]);
    }
}
