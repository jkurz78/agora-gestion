<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    public function exportCsv(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->compteDeResultat($exercice);

        $rows = [];
        foreach ($data['charges'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = [
                    'Charge',
                    $cat['label'],
                    $sc['label'],
                    $sc['montant_n1'] !== null ? number_format((float) $sc['montant_n1'], 2, ',', '') : '',
                    number_format((float) $sc['montant_n'], 2, ',', ''),
                    $sc['budget'] !== null ? number_format((float) $sc['budget'], 2, ',', '') : '',
                    $sc['budget'] !== null ? number_format((float) $sc['montant_n'] - (float) $sc['budget'], 2, ',', '') : '',
                ];
            }
        }
        foreach ($data['produits'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = [
                    'Produit',
                    $cat['label'],
                    $sc['label'],
                    $sc['montant_n1'] !== null ? number_format((float) $sc['montant_n1'], 2, ',', '') : '',
                    number_format((float) $sc['montant_n'], 2, ',', ''),
                    $sc['budget'] !== null ? number_format((float) $sc['budget'], 2, ',', '') : '',
                    $sc['budget'] !== null ? number_format((float) $sc['montant_n'] - (float) $sc['budget'], 2, ',', '') : '',
                ];
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, ['Type', 'Catégorie', 'Sous-catégorie', 'N-1', 'N', 'Budget', 'Écart']);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'compte_resultat_'.$exercice.'.csv', ['Content-Type' => 'text/csv']);
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
