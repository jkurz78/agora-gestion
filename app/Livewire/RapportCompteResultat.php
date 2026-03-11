<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultat extends Component
{
    public int $exercice;

    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public function mount(): void
    {
        $this->exercice = app(ExerciceService::class)->current();
    }

    public function exportCsv()
    {
        $rapportService = app(RapportService::class);
        $operationIds = array_filter($this->selectedOperationIds) ?: null;
        $data = $rapportService->compteDeResultat($this->exercice, $operationIds);

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
        }, 'compte_resultat_' . $this->exercice . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function render()
    {
        $exerciceService = app(ExerciceService::class);
        $rapportService = app(RapportService::class);

        $operationIds = array_filter($this->selectedOperationIds) ?: null;
        $data = $rapportService->compteDeResultat($this->exercice, $operationIds);

        return view('livewire.rapport-compte-resultat', [
            'charges' => $data['charges'],
            'produits' => $data['produits'],
            'exercices' => $exerciceService->available(),
            'exerciceService' => $exerciceService,
            'operations' => Operation::orderBy('nom')->get(),
        ]);
    }
}
