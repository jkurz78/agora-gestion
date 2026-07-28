<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\Rapports\BalanceComptableBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportBalance extends Component
{
    #[Url(as: 'du')]
    public string $dateDebut = '';

    #[Url(as: 'au')]
    public string $dateFin = '';

    #[Url(as: 'comptes')]
    public string $comptes = '1,2,3,4,5,6,7';

    #[Url(as: 'colonnes')]
    public int $colonnes = 6;

    public function mount(ExerciceService $exerciceService): void
    {
        $range = $exerciceService->dateRange($exerciceService->current());

        if ($this->dateDebut === '') {
            $this->dateDebut = $range['start']->toDateString();
        }

        if ($this->dateFin === '') {
            $this->dateFin = $range['end']->toDateString();
        }

        $this->normaliserColonnes();
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

    public function updatedColonnes(): void
    {
        $this->normaliserColonnes();
    }

    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('rapports.export', [
            'rapport' => 'balance',
            'format' => $format,
            'exercice' => $exercice,
            'du' => $this->dateDebut,
            'au' => $this->dateFin,
            'comptes' => $this->comptes,
            'colonnes' => $this->colonnes,
        ]);
    }

    public function formatCentimes(int $centimes): string
    {
        if ($centimes === 0) {
            return '—';
        }

        return number_format($centimes / 100, 2, ',', ' ').' €';
    }

    public function render(BalanceComptableBuilder $builder): View
    {
        $this->normaliserColonnes();

        return view('livewire.rapport-balance', [
            'balance' => $builder->balance(
                $this->dateDebut,
                $this->dateFin,
                $this->prefixesComptes(),
            ),
        ]);
    }

    private function normaliserColonnes(): void
    {
        if (! in_array($this->colonnes, [2, 4, 6], true)) {
            $this->colonnes = 6;
        }
    }
}
