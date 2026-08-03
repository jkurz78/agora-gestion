<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\JournalComptable;
use App\Services\ExerciceService;
use App\Services\Rapports\JournauxBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportJournaux extends Component
{
    #[Url(as: 'du')]
    public string $dateDebut = '';

    #[Url(as: 'au')]
    public string $dateFin = '';

    /**
     * Journaux retenus. Vide = tous — c'est le défaut, et c'est aussi ce que
     * produit le décochage complet des cases.
     *
     * @var array<int, string>
     */
    #[Url(as: 'journaux')]
    public array $journaux = [];

    #[Url(as: 'mode_reglement')]
    public bool $afficherModeReglement = false;

    #[Url(as: 'justificatif')]
    public bool $afficherJustificatif = false;

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
     * @return array<int, array{code: string, libelle: string}>
     */
    public function journauxDisponibles(): array
    {
        return collect(JournalComptable::cases())
            ->map(fn (JournalComptable $journal): array => [
                'code' => $journal->value,
                'libelle' => $journal->label(),
            ])
            ->all();
    }

    public function toutSelectionner(): void
    {
        $this->journaux = [];
    }

    public function exportUrl(string $format): string
    {
        return route('rapports.export', [
            'rapport' => 'journaux',
            'format' => $format,
            'exercice' => app(ExerciceService::class)->current(),
            'du' => $this->dateDebut,
            'au' => $this->dateFin,
            'journaux' => $this->journaux,
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

    /** Nombre de colonnes du tableau, pour les colspans des lignes de synthèse. */
    public function nombreColonnes(): int
    {
        return 6 + ($this->afficherJustificatif ? 1 : 0);
    }

    public function render(JournauxBuilder $builder): View
    {
        return view('livewire.rapport-journaux', [
            'journal' => $builder->journaux(
                $this->dateDebut,
                $this->dateFin,
                $this->journaux,
            ),
        ]);
    }
}
