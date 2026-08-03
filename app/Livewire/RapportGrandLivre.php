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

    /** N'affiche que les écritures non lettrées : la position ouverte du compte. */
    #[Url(as: 'non_lettrees')]
    public bool $uniquementNonLettrees = false;

    // Colonnes optionnelles : purement présentation, sans effet sur les soldes.

    #[Url(as: 'mode_reglement')]
    public bool $afficherModeReglement = false;

    #[Url(as: 'justificatif')]
    public bool $afficherJustificatif = false;

    /** Nombre de colonnes du tableau, pour les colspans des lignes de synthèse. */
    public function nombreColonnes(): int
    {
        return 9
            + ($this->afficherModeReglement ? 1 : 0)
            + ($this->afficherJustificatif ? 1 : 0);
    }

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

    public function exportUrl(string $format): string
    {
        return route('rapports.export', [
            'rapport' => 'grand-livre',
            'format' => $format,
            'exercice' => app(ExerciceService::class)->current(),
            'du' => $this->dateDebut,
            'au' => $this->dateFin,
            'comptes' => $this->comptes,
            'non_soldes' => $this->uniquementNonSoldes ? 1 : 0,
            'non_lettrees' => $this->uniquementNonLettrees ? 1 : 0,
            'mode_reglement' => $this->afficherModeReglement ? 1 : 0,
        ]);
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
                $this->uniquementNonLettrees,
            ),
        ]);
    }
}
