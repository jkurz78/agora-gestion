<?php

declare(strict_types=1);

namespace App\Livewire\Immobilisations;

use App\Models\Immobilisation;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

final class ImmobilisationIndex extends Component
{
    public function render(): View
    {
        /** @var Collection<int, Immobilisation> $immobilisations */
        $immobilisations = Immobilisation::query()
            ->with(['compte', 'dotations'])
            ->orderBy('numero')
            ->get();

        return view('livewire.immobilisations.immobilisation-index', [
            'immobilisations' => $immobilisations,
            'totalBrutCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->montantAcquisitionCentimes()
            ),
            'totalCumulCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->cumulAmortiCentimes()
            ),
            'totalNetCentimes' => $immobilisations->sum(
                fn (Immobilisation $i): int => $i->valeurNetteCentimes()
            ),
        ])->layout('layouts.app-sidebar', ['title' => 'Immobilisations']);
    }
}
