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
    public bool $parSeances = false;

    #[Url(as: 'tiers')]
    public bool $parTiers = false;

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
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();

        $operationTree = $this->buildOperationTree($exercice);

        $charges = [];
        $produits = [];
        $seances = [];
        $totalCharges = 0.0;
        $totalProduits = 0.0;
        $hasSelection = ! empty($this->selectedOperationIds);

        if ($hasSelection) {
            $data = app(RapportService::class)->compteDeResultatOperations(
                $exercice,
                $this->selectedOperationIds,
                $this->parSeances,
                $this->parTiers,
            );
            $charges = $data['charges'];
            $produits = $data['produits'];
            $seances = $data['seances'] ?? [];
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
            'seances' => $seances,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
            'hasSelection' => $hasSelection,
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
