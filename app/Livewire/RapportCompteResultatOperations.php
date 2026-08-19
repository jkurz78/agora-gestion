<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PorteeExercices;
use App\Models\Operation;
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
    public string $mode = 'realise';  // 'realise' | 'projection'

    #[Url(as: 'parops')]
    public bool $parOperations = false;

    /**
     * `current` (défaut) ou `all`. Toute autre valeur retombe sur `current` :
     * l'URL est une entrée utilisateur comme une autre.
     */
    #[Url(as: 'exercices')]
    public string $porteeExercices = 'current';

    /**
     * Vrai quand la sélection reçue par l'URL a été entièrement écartée parce
     * qu'aucune de ces opérations n'a de mouvement sur l'exercice affiché.
     * Information non bloquante affichée dans la vue.
     */
    public bool $selectionIgnoree = false;

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
            'exercices' => PorteeExercices::depuisRequete($this->porteeExercices)->value,
        ]);
    }

    public function render(): mixed
    {
        $exercice = app(ExerciceService::class)->current();
        $rapportService = app(RapportService::class);
        $portee = PorteeExercices::depuisRequete($this->porteeExercices);

        // EX-03 : en mode projection, l'arbre et la sélection doivent aussi
        // admettre les opérations qui n'ont encore que des prévisions —
        // sinon une activité future planifiée mais non commencée serait
        // insélectionnable, ce qui viderait la projection de son objet.
        $previsionnel = $this->mode !== 'realise';

        $eligibleIds = $rapportService->operationsEligibles($exercice, $previsionnel);
        $operationTree = $this->buildOperationTree($eligibleIds);

        // SEL-04 : les ids reçus par l'URL ne sont jamais fiables — on les
        // intersecte avec les éligibles avant tout calcul.
        $demandes = collect($this->selectedOperationIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $selection = array_values(array_intersect($demandes, $eligibleIds));

        $this->selectionIgnoree = $demandes !== [] && $selection === [];

        $charges = [];
        $produits = [];
        $previsionsCharges = [];
        $previsionsProduits = [];
        $seances = [];
        $operationNames = [];
        $seancesParOperation = [];
        $projCharges = null;
        $projProduits = null;
        $totalCharges = 0.0;
        $totalProduits = 0.0;
        $exercices = [];
        $projChargesParExercice = [];
        $projProduitsParExercice = [];
        $hasSelection = $selection !== [];

        if ($hasSelection) {
            $data = $rapportService->compteDeResultatOperations(
                $exercice,
                $selection,
                $this->parSeances,
                $this->parTiers,
                $previsionnel,
                $this->parOperations,
                $portee,
            );
            $charges = $data['charges'];
            $produits = $data['produits'];
            $seances = $data['seances'] ?? [];
            $previsionsCharges = $data['previsions_charges'] ?? [];
            $previsionsProduits = $data['previsions_produits'] ?? [];
            $operationNames = $data['operation_names'] ?? [];
            $seancesParOperation = $data['seances_par_operation'] ?? [];
            $projCharges = $data['proj_charges'] ?? null;
            $projProduits = $data['proj_produits'] ?? null;
            $exercices = $data['exercices'] ?? [];
            $projChargesParExercice = $data['proj_charges_par_exercice'] ?? [];
            $projProduitsParExercice = $data['proj_produits_par_exercice'] ?? [];

            if ($this->mode === 'projection' && $projCharges !== null) {
                $totalCharges = $projCharges->total();
                $totalProduits = $projProduits->total();
            } elseif ($portee === PorteeExercices::Tous) {
                // En portée « tous les exercices », le total couvre exactement
                // les exercices affichés — jamais un montant global qui
                // inclurait en silence un exercice absent des lignes.
                $totalCharges = collect($charges)->sum('montant_exercices');
                $totalProduits = collect($produits)->sum('montant_exercices');
            } else {
                $totalCharges = collect($charges)->sum('montant');
                $totalProduits = collect($produits)->sum('montant');
            }
        }

        return view('livewire.rapport-compte-resultat-operations', [
            'operationTree' => $operationTree,
            'charges' => $charges,
            'produits' => $produits,
            'previsionsCharges' => $previsionsCharges,
            'previsionsProduits' => $previsionsProduits,
            'seances' => $seances,
            'operationNames' => $operationNames,
            'seancesParOperation' => $seancesParOperation,
            'projCharges' => $projCharges,
            'projProduits' => $projProduits,
            'totalCharges' => $totalCharges,
            'totalProduits' => $totalProduits,
            'resultatNet' => $totalProduits - $totalCharges,
            'hasSelection' => $hasSelection,
            'mode' => $this->mode,
            'parOperations' => $this->parOperations,
            'aucuneOperationEligible' => $operationTree === [],
            'selectionIgnoree' => $this->selectionIgnoree,
            'exercices' => $exercices,
            'porteeExercices' => $portee->value,
            'projChargesParExercice' => $projChargesParExercice,
            'projProduitsParExercice' => $projProduitsParExercice,
        ]);
    }

    /**
     * Arbre de regroupement pour le sélecteur d'opérations (groupe par compte,
     * puis par type d'opération).
     *
     * SEL-03 : l'arbre part des opérations ÉLIGIBLES, pas de
     * TypeOperation::actif()->operations()->forExercice(). Un type désactivé
     * après coup ou une opération datée sur une autre période ne doivent pas
     * faire disparaître des montants bien réels de l'exercice affiché.
     *
     * L'id de premier niveau n'est qu'une clé de groupement locale à ce widget
     * JS — jamais renvoyée au serveur (seuls les op.id le sont via
     * selectedOperationIds, revalidés en SEL-04).
     *
     * @param  list<int>  $eligibleIds
     * @return list<array{id: int, nom: string, types: list<array>}>
     */
    private function buildOperationTree(array $eligibleIds): array
    {
        if ($eligibleIds === []) {
            return [];
        }

        $operations = Operation::whereIn('id', $eligibleIds)
            ->with('typeOperation.compte')
            ->orderBy('nom')
            ->get();

        $tree = [];
        foreach ($operations as $op) {
            $type = $op->typeOperation;
            $compte = $type?->compte;

            $cId = (int) ($compte?->id ?? 0);
            $tId = (int) ($type?->id ?? 0);

            if (! isset($tree[$cId])) {
                $tree[$cId] = [
                    'id' => $cId,
                    'nom' => $compte?->intitule ?? '—',
                    'types_map' => [],
                ];
            }
            if (! isset($tree[$cId]['types_map'][$tId])) {
                $tree[$cId]['types_map'][$tId] = [
                    'id' => $tId,
                    'nom' => $type?->nom ?? 'Sans type',
                    'operations' => [],
                ];
            }

            $tree[$cId]['types_map'][$tId]['operations'][] = [
                'id' => (int) $op->id,
                'nom' => $op->nom,
            ];
        }

        $result = [];
        foreach ($tree as $groupe) {
            $types = array_values($groupe['types_map']);
            usort($types, fn (array $a, array $b): int => strcmp($a['nom'], $b['nom']));
            unset($groupe['types_map']);
            $groupe['types'] = $types;
            $result[] = $groupe;
        }
        usort($result, fn (array $a, array $b): int => strcmp($a['nom'], $b['nom']));

        return $result;
    }
}
