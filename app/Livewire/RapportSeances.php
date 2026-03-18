<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportSeances extends Component
{
    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public function exportCsv(): mixed
    {
        if (empty($this->selectedOperationIds)) {
            return null;
        }

        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->rapportSeances($exercice, $this->selectedOperationIds);

        $seances = $data['seances'];
        $headers = ['Type', 'Catégorie', 'Sous-catégorie'];
        foreach ($seances as $s) {
            $headers[] = 'Séance '.$s;
        }
        $headers[] = 'Total';

        $rows = [];
        foreach ([['data' => $data['charges'], 'type' => 'Charge'], ['data' => $data['produits'], 'type' => 'Produit']] as $section) {
            foreach ($section['data'] as $cat) {
                foreach ($cat['sous_categories'] as $sc) {
                    $row = [$section['type'], $cat['label'], $sc['label']];
                    foreach ($seances as $s) {
                        $row[] = number_format($sc['seances'][$s] ?? 0.0, 2, ',', '');
                    }
                    $row[] = number_format($sc['total'], 2, ',', '');
                    $rows[] = $row;
                }
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, $headers);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'rapport_seances_'.$exercice.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $operations = Operation::forExercice($exercice)->whereNotNull('nombre_seances')
            ->where('nombre_seances', '>', 0)
            ->orderBy('nom')
            ->get();

        $seances = [];
        $charges = [];
        $produits = [];
        $totalChargesN = 0.0;
        $totalProduitsN = 0.0;

        if (! empty($this->selectedOperationIds)) {
            $data = app(RapportService::class)->rapportSeances($exercice, $this->selectedOperationIds);
            $seances = $data['seances'];
            $charges = $data['charges'];
            $produits = $data['produits'];
            $totalChargesN = collect($charges)->sum('total');
            $totalProduitsN = collect($produits)->sum('total');
        }

        return view('livewire.rapport-seances', [
            'operations' => $operations,
            'seances' => $seances,
            'charges' => $charges,
            'produits' => $produits,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet' => $totalProduitsN - $totalChargesN,
            'hasSelection' => ! empty($this->selectedOperationIds),
        ]);
    }
}
