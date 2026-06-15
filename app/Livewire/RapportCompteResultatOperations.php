<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\TypeOperation;
use App\Services\ExerciceService;
use App\Services\RapportService;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RapportCompteResultatOperations extends Component
{
    /** @var array<int, int> */
    #[Url(as: 'ops')]
    public array $selectedOperationIds = [];

    #[Url(as: 'seances')]
    public bool $parSeances = true;

    #[Url(as: 'tiers')]
    public bool $parTiers = true;

    #[Url(as: 'mode')]
    public string $mode = 'realise';  // 'realise' | 'comparaison' | 'projection'

    #[Url(as: 'parops')]
    public bool $parOperations = false;

    public function updatedParOperations(bool $value): void
    {
        if ($value) {
            $this->parSeances = false;
            $this->parTiers = false;
        }
    }

    public function updatedParSeances(bool $value): void
    {
        if ($value) {
            $this->parOperations = false;
        }
    }

    public function updatedParTiers(bool $value): void
    {
        if ($value) {
            $this->parOperations = false;
        }
    }

    public function exportUrl(string $format): string
    {
        $exercice = app(ExerciceService::class)->current();

        return route('rapports.export', [
            'rapport' => 'operations',
            'format' => $format,
            'exercice' => $exercice,
            'ops' => $this->selectedOperationIds,
            'seances' => $this->parSeances ? '1' : '0',
            'tiers' => $this->parTiers ? '1' : '0',
            'mode' => $this->mode,
            'parops' => $this->parOperations ? '1' : '0',
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();

        $operationTree = $this->buildOperationTree($exercice);

        $charges = [];
        $produits = [];
        $previsionsCharges = [];
        $previsionsProduits = [];
        $seances = [];
        $operationNames = [];
        $projections = null;
        $totalCharges = 0.0;
        $totalProduits = 0.0;
        $hasSelection = ! empty($this->selectedOperationIds);

        $previsionnel = $this->mode !== 'realise';

        if ($hasSelection) {
            $data = app(RapportService::class)->compteDeResultatOperations(
                $exercice,
                $this->selectedOperationIds,
                $this->parSeances,
                $this->parTiers,
                $previsionnel,
                $this->parOperations,
            );
            $charges = $data['charges'];
            $produits = $data['produits'];
            $seances = $data['seances'] ?? [];
            $previsionsCharges = $data['previsions_charges'] ?? [];
            $previsionsProduits = $data['previsions_produits'] ?? [];
            $operationNames = $data['operation_names'] ?? [];
            $projections = $data['projections'] ?? null;
            $totalCharges = $this->parSeances
                ? collect($charges)->sum('total')
                : collect($charges)->sum('montant');
            $totalProduits = $this->parSeances
                ? collect($produits)->sum('total')
                : collect($produits)->sum('montant');
        }

        return view('livewire.rapport-compte-resultat-operations', [
            'operationTree' => $operationTree,
            'charges' => $charges,
            'produits' => $produits,
            'previsionsCharges' => $previsionsCharges,
            'previsionsProduits' => $previsionsProduits,
            'seances' => $seances,
            'operationNames' => $operationNames,
            'projections' => $projections,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
            'hasSelection' => $hasSelection,
            'mode' => $this->mode,
            'parOperations' => $this->parOperations,
        ]);
    }

    /** @return list<array{id: int, nom: string, types: list<array>}> */
    private function buildOperationTree(int $exercice): array
    {
        $typeOperations = TypeOperation::actif()
            ->with(['sousCategorie', 'operations' => fn ($q) => $q->forExercice($exercice)->orderBy('nom')])
            ->orderBy('nom')
            ->get();

        $tree = [];
        foreach ($typeOperations as $type) {
            if ($type->operations->isEmpty()) {
                continue;
            }
            $scId = $type->sous_categorie_id;
            if (! isset($tree[$scId])) {
                $tree[$scId] = [
                    'id' => $scId,
                    'nom' => $type->sousCategorie->nom,
                    'types' => [],
                ];
            }
            $tree[$scId]['types'][] = [
                'id' => $type->id,
                'nom' => $type->nom,
                'operations' => $type->operations
                    ->map(fn ($op) => ['id' => $op->id, 'nom' => $op->nom])
                    ->values()
                    ->all(),
            ];
        }

        return collect($tree)->sortBy('nom')->values()->all();
    }
}
