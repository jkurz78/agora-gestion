<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public function exportCsv()
    {
        $rapportService = app(RapportService::class);
        $exercice = app(ExerciceService::class)->current();
        $operationIds = array_filter($this->selectedOperationIds) ?: null;
        $data = $rapportService->compteDeResultat($exercice, $operationIds);

        $rows = [];
        foreach ($data['charges'] as $charge) {
            $rows[] = ['Charge', $charge['code_cerfa'] ?? '', $charge['label'], number_format($charge['montant'], 2, ',', '')];
        }
        foreach ($data['produits'] as $produit) {
            $rows[] = ['Produit', $produit['code_cerfa'] ?? '', $produit['label'], number_format($produit['montant'], 2, ',', '')];
        }

        $csv = $rapportService->toCsv($rows, ['Type', 'Code CERFA', 'Libellé', 'Montant']);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'compte_resultat_'.$exercice.'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function render()
    {
        $exercice = app(ExerciceService::class)->current();
        $rapportService = app(RapportService::class);

        $operationIds = array_filter($this->selectedOperationIds) ?: null;
        $data = $rapportService->compteDeResultat($exercice, $operationIds);

        return view('livewire.rapport-compte-resultat', [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'operations' => Operation::orderBy('nom')->get(),
        ]);
    }
}
