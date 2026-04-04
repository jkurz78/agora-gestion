<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('compta.rapports.export', [
            'rapport' => 'compte-resultat',
            'format' => $format,
            'exercice' => $exercice,
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->compteDeResultat($exercice);

        $labelN = $exercice.'–'.($exercice + 1);
        $labelN1 = ($exercice - 1).'–'.$exercice;

        $totalChargesN = collect($data['charges'])->sum('montant_n');
        $totalProduitsN = collect($data['produits'])->sum('montant_n');
        $resultatNet = $totalProduitsN - $totalChargesN;

        return view('livewire.rapport-compte-resultat', [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'labelN' => $labelN,
            'labelN1' => $labelN1,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet' => $resultatNet,
        ]);
    }
}
