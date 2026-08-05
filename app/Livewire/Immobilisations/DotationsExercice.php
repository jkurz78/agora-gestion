<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Exceptions\Immobilisation\DotationInterditeException;
use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\DotationService;
use Illuminate\View\View;
use Livewire\Component;

final class DotationsExercice extends Component
{
    public int $exercice = 0;

    public string $flashMessage = '';

    public string $flashType = '';

    public function mount(): void
    {
        // Par défaut, l'exercice précédent : c'est celui qu'on clôture.
        $this->exercice = app(ExerciceService::class)->current() - 1;
    }

    public function render(): View
    {
        return view('livewire.immobilisations.dotations-exercice', [
            'lignes' => app(DotationService::class)->apercu($this->exercice),
            'exerciceService' => app(ExerciceService::class),
            'exercicesDisponibles' => app(ExerciceService::class)->availableYears(),
        ])->layout('layouts.app-sidebar', ['title' => 'Dotations aux amortissements']);
    }

    public function genererTout(): void
    {
        try {
            $nombre = app(DotationService::class)->generer($this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = $nombre === 0
            ? 'Aucune dotation à générer pour cet exercice.'
            : $nombre.' dotation'.($nombre > 1 ? 's générées' : ' générée').'. Pensez à les ventiler avant de clôturer.';
        $this->flashType = 'success';
    }

    public function recalculer(int $immobilisationId): void
    {
        $immobilisation = Immobilisation::findOrFail($immobilisationId);

        try {
            app(DotationService::class)->recalculer($immobilisation, $this->exercice);
        } catch (DotationInterditeException $e) {
            $this->flashMessage = $e->getMessage();
            $this->flashType = 'warning';

            return;
        }

        $this->flashMessage = 'Dotation recalculée pour '.$immobilisation->numero
            .'. Si elle avait été ventilée, la ventilation est à refaire.';
        $this->flashType = 'success';
    }
}
