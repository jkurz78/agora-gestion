<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Operation;
use App\Models\TypeOperation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Component;

final class RapportCompteResultatOperations extends Component
{
    /** @var array<int, int> */
    public array $selectedOperationIds = [];

    public ?int $filterTypeId = null;

    public function exportCsv(): mixed
    {
        if (empty($this->selectedOperationIds)) {
            return null;
        }

        $exercice = app(ExerciceService::class)->current();
        $data = app(RapportService::class)->compteDeResultatOperations($exercice, $this->selectedOperationIds);

        $rows = [];
        foreach ($data['charges'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = ['Charge', $cat['label'], $sc['label'], number_format((float) $sc['montant'], 2, ',', '')];
            }
        }
        foreach ($data['produits'] as $cat) {
            foreach ($cat['sous_categories'] as $sc) {
                $rows[] = ['Produit', $cat['label'], $sc['label'], number_format((float) $sc['montant'], 2, ',', '')];
            }
        }

        $csv = app(RapportService::class)->toCsv($rows, ['Type', 'Catégorie', 'Sous-catégorie', 'Montant']);

        return response()->streamDownload(function () use ($csv) {
            echo $csv;
        }, 'cr_operations_'.$exercice.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function updatedFilterTypeId(): void
    {
        $this->selectedOperationIds = [];
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $typeOperations = TypeOperation::actif()->orderBy('code')->get();

        $query = Operation::forExercice($exercice)->orderBy('nom');
        if ($this->filterTypeId !== null) {
            $query->where('type_operation_id', $this->filterTypeId);
        }
        $operations = $query->get();

        $charges = [];
        $produits = [];
        $totalChargesN = 0.0;
        $totalProduitsN = 0.0;

        if (! empty($this->selectedOperationIds)) {
            $data = app(RapportService::class)->compteDeResultatOperations($exercice, $this->selectedOperationIds);
            $charges = $data['charges'];
            $produits = $data['produits'];
            $totalChargesN = collect($charges)->sum('montant');
            $totalProduitsN = collect($produits)->sum('montant');
        }

        return view('livewire.rapport-compte-resultat-operations', [
            'operations' => $operations,
            'typeOperations' => $typeOperations,
            'charges' => $charges,
            'produits' => $produits,
            'totalChargesN' => $totalChargesN,
            'totalProduitsN' => $totalProduitsN,
            'resultatNet' => $totalProduitsN - $totalChargesN,
            'hasSelection' => ! empty($this->selectedOperationIds),
        ]);
    }
}
