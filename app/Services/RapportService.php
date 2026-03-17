<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cotisation;
use App\Models\DepenseLigne;
use App\Models\Don;
use App\Models\RecetteLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RapportService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compte de résultat complet : hiérarchie catégorie/sous-catégorie avec N-1 et budget.
     * Pas de filtre opération.
     *
     * @return array{charges: list<array>, produits: list<array>}
     */
    public function compteDeResultat(int $exercice): array
    {
        [$startN, $endN] = $this->exerciceDates($exercice);
        [$startN1, $endN1] = $this->exerciceDates($exercice - 1);

        $chargesN = $this->fetchDepenseRows($startN, $endN);
        $chargesN1 = $this->fetchDepenseRows($startN1, $endN1);

        $produitsN = $this->fetchProduitsRows($startN, $endN, $exercice);
        $produitsN1 = $this->fetchProduitsRows($startN1, $endN1, $exercice - 1);

        $budgetMap = $this->fetchBudgetMap($exercice);

        return [
            'charges' => $this->buildHierarchyFull($chargesN, $chargesN1, $budgetMap),
            'produits' => $this->buildHierarchyFull($produitsN, $produitsN1, $budgetMap),
        ];
    }

    /**
     * Compte de résultat filtré par opérations. Pas de N-1 ni budget. Cotisations exclues.
     *
     * @param  array<int>  $operationIds
     * @return array{charges: list<array>, produits: list<array>}
     */
    public function compteDeResultatOperations(int $exercice, array $operationIds): array
    {
        [$start, $end] = $this->exerciceDates($exercice);

        $charges = $this->fetchDepenseRows($start, $end, $operationIds);
        $produits = $this->fetchProduitsRows($start, $end, $exercice, $operationIds);

        return [
            'charges' => $this->buildHierarchySimple($charges),
            'produits' => $this->buildHierarchySimple($produits),
        ];
    }

    /**
     * Rapport par séances : hiérarchie catégorie/sous-catégorie avec une colonne par séance.
     *
     * @param  array<int>  $operationIds
     * @return array{seances: list<int>, charges: list<array>, produits: list<array>}
     */
    public function rapportSeances(int $exercice, array $operationIds): array
    {
        [$start, $end] = $this->exerciceDates($exercice);

        $chargeRows = $this->fetchDepenseSeancesRows($start, $end, $operationIds);
        $produitsRows = $this->fetchProduitsSeancesRows($start, $end, $operationIds);

        $allSeances = collect($chargeRows)
            ->merge($produitsRows)
            ->pluck('seance')
            ->unique()
            ->sort()
            ->values()
            ->map(fn ($s) => (int) $s)
            ->all();

        return [
            'seances' => $allSeances,
            'charges' => $this->buildHierarchySeances($chargeRows, $allSeances),
            'produits' => $this->buildHierarchySeances($produitsRows, $allSeances),
        ];
    }

    /**
     * Génère un CSV avec séparateur point-virgule (convention française).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    public function toCsv(array $rows, array $headers): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, ';');
        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // ── Private helpers — requêtes SQL ────────────────────────────────────────

    /** @return array{string, string} */
    private function exerciceDates(int $exercice): array
    {
        return ["{$exercice}-09-01", ($exercice + 1).'-08-31'];
    }

    /**
     * Agrégation des dépenses par (catégorie, sous-catégorie).
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre
     * @return Collection<int, object>
     */
    private function fetchDepenseRows(string $start, string $end, ?array $operationIds = null): Collection
    {
        $map = [];
        $this->accumulerDepensesResolues($start, $end, $operationIds, $map);
        return collect(array_values($map))->map(fn ($row) => (object) $row);
    }

    /**
     * Accumule les dépenses en résolvant les affectations.
     * Lignes avec affectations → utilise les affectations.
     * Lignes sans affectations → utilise operation_id de la ligne.
     *
     * @param array<int>|null $operationIds
     * @param array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,montant:float}> $map
     */
    private function accumulerDepensesResolues(string $start, string $end, ?array $operationIds, array &$map): void
    {
        // Partie 1 : lignes sans affectations
        $q1 = DB::table('depense_lignes')
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->leftJoin('depense_ligne_affectations as dla', 'dla.depense_ligne_id', '=', 'depense_lignes.id')
            ->whereNull('depense_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereNull('dla.id')
            ->whereBetween('d.date', [$start, $end])
            ->select([
                'c.id as categorie_id', 'c.nom as categorie_nom',
                'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
                DB::raw('SUM(depense_lignes.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

        if ($operationIds !== null) {
            $q1->whereIn('depense_lignes.operation_id', $operationIds);
        }

        // Partie 2 : lignes avec affectations (utiliser affectation.montant et affectation.operation_id)
        $q2 = DB::table('depense_ligne_affectations as dla')
            ->join('depense_lignes', 'depense_lignes.id', '=', 'dla.depense_ligne_id')
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->whereNull('depense_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereBetween('d.date', [$start, $end])
            ->select([
                'c.id as categorie_id', 'c.nom as categorie_nom',
                'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
                DB::raw('SUM(dla.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

        if ($operationIds !== null) {
            $q2->whereIn('dla.operation_id', $operationIds);
        }

        foreach ([$q1->get(), $q2->get()] as $rows) {
            foreach ($rows as $row) {
                $scId = (int) $row->sous_categorie_id;
                if (isset($map[$scId])) {
                    $map[$scId]['montant'] += (float) $row->montant;
                } else {
                    $map[$scId] = [
                        'categorie_id'       => (int) $row->categorie_id,
                        'categorie_nom'      => $row->categorie_nom,
                        'sous_categorie_id'  => $scId,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'montant'            => (float) $row->montant,
                    ];
                }
            }
        }
    }

    /**
     * Agrégation des produits (recettes + dons + cotisations si pas de filtre opération).
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre, cotisations incluses
     * @return Collection<int, object>
     */
    private function fetchProduitsRows(string $start, string $end, int $exercice, ?array $operationIds = null): Collection
    {
        // Map intermédiaire keyed by sous_categorie_id
        /** @var array<int, array{categorie_id: int, categorie_nom: string, sous_categorie_id: int, sous_categorie_nom: string, montant: float}> */
        $map = [];

        $accumuler = function (Collection $rows) use (&$map): void {
            foreach ($rows as $row) {
                $scId = (int) $row->sous_categorie_id;
                if (isset($map[$scId])) {
                    $map[$scId]['montant'] += (float) $row->montant;
                } else {
                    $map[$scId] = [
                        'categorie_id' => (int) $row->categorie_id,
                        'categorie_nom' => $row->categorie_nom,
                        'sous_categorie_id' => $scId,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'montant' => (float) $row->montant,
                    ];
                }
            }
        };

        // Recettes
        $rq = RecetteLigne::query()
            ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
            ->whereNull('recette_lignes.deleted_at')
            ->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(recette_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $rq->whereIn('recette_lignes.operation_id', $operationIds);
        }
        $accumuler($rq->get());

        // Dons (sous_categorie_id nullable → INNER JOIN exclut ceux sans sous-catégorie)
        $dq = Don::query()
            ->join('sous_categories as sc', 'dons.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->whereNull('dons.deleted_at')
            ->whereBetween('dons.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(dons.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $dq->whereIn('dons.operation_id', $operationIds);
        }
        $accumuler($dq->get());

        // Cotisations — uniquement sans filtre opération (elles n'ont pas d'operation_id)
        if ($operationIds === null) {
            $cq = Cotisation::query()
                ->join('sous_categories as sc', 'cotisations.sous_categorie_id', '=', 'sc.id')
                ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
                ->whereNull('cotisations.deleted_at')
                ->where('cotisations.exercice', $exercice)
                ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(cotisations.montant) as montant')])
                ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
            $accumuler($cq->get());
        }

        return collect(array_values($map))->map(fn ($row) => (object) $row);
    }

    /**
     * Agrégation des dépenses par (catégorie, sous-catégorie, séance).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchDepenseSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        $map = [];
        $this->accumulerDepensesSeancesResolues($start, $end, $operationIds, $map);
        $flat = [];
        foreach ($map as $seanceMap) {
            foreach ($seanceMap as $entry) {
                $flat[] = $entry;
            }
        }
        return collect($flat)->map(fn ($row) => (object) $row);
    }

    /**
     * @param array<int> $operationIds
     * @param array<int, array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,seance:int,montant:float}>> $map
     */
    private function accumulerDepensesSeancesResolues(string $start, string $end, array $operationIds, array &$map): void
    {
        // Lignes sans affectations
        $rows1 = DB::table('depense_lignes')
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->leftJoin('depense_ligne_affectations as dla', 'dla.depense_ligne_id', '=', 'depense_lignes.id')
            ->whereNull('depense_lignes.deleted_at')->whereNull('d.deleted_at')
            ->whereNull('dla.id')
            ->whereNotNull('depense_lignes.seance')
            ->whereIn('depense_lignes.operation_id', $operationIds)
            ->whereBetween('d.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'depense_lignes.seance', DB::raw('SUM(depense_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'depense_lignes.seance')
            ->get();

        // Lignes avec affectations
        $rows2 = DB::table('depense_ligne_affectations as dla')
            ->join('depense_lignes', 'depense_lignes.id', '=', 'dla.depense_ligne_id')
            ->join('sous_categories as sc', 'depense_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('depenses as d', 'd.id', '=', 'depense_lignes.depense_id')
            ->whereNull('depense_lignes.deleted_at')->whereNull('d.deleted_at')
            ->whereNotNull('dla.seance')
            ->whereIn('dla.operation_id', $operationIds)
            ->whereBetween('d.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'dla.seance', DB::raw('SUM(dla.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'dla.seance')
            ->get();

        foreach ([$rows1, $rows2] as $rows) {
            foreach ($rows as $row) {
                $scId = (int) $row->sous_categorie_id;
                $seance = (int) $row->seance;
                if (isset($map[$scId][$seance])) {
                    $map[$scId][$seance]['montant'] += (float) $row->montant;
                } else {
                    $map[$scId][$seance] = [
                        'categorie_id' => (int) $row->categorie_id, 'categorie_nom' => $row->categorie_nom,
                        'sous_categorie_id' => $scId, 'sous_categorie_nom' => $row->sous_categorie_nom,
                        'seance' => $seance, 'montant' => (float) $row->montant,
                    ];
                }
            }
        }
    }

    /**
     * Agrégation des produits par séance (recettes + dons ; cotisations exclues).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        /** @var array<int, array<int, array{categorie_id: int, categorie_nom: string, sous_categorie_id: int, sous_categorie_nom: string, seance: int, montant: float}>> */
        $map = [];

        $accumuler = function (Collection $rows) use (&$map): void {
            foreach ($rows as $row) {
                $scId = (int) $row->sous_categorie_id;
                $seance = (int) $row->seance;
                if (isset($map[$scId][$seance])) {
                    $map[$scId][$seance]['montant'] += (float) $row->montant;
                } else {
                    $map[$scId][$seance] = [
                        'categorie_id' => (int) $row->categorie_id,
                        'categorie_nom' => $row->categorie_nom,
                        'sous_categorie_id' => $scId,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'seance' => $seance,
                        'montant' => (float) $row->montant,
                    ];
                }
            }
        };

        // Recettes par séance
        $accumuler(RecetteLigne::query()
            ->join('sous_categories as sc', 'recette_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('recettes as r', 'r.id', '=', 'recette_lignes.recette_id')
            ->whereNull('recette_lignes.deleted_at')
            ->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$start, $end])
            ->whereNotNull('recette_lignes.seance')
            ->whereIn('recette_lignes.operation_id', $operationIds)
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'recette_lignes.seance', DB::raw('SUM(recette_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'recette_lignes.seance')
            ->get());

        // Dons par séance
        $accumuler(Don::query()
            ->join('sous_categories as sc', 'dons.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->whereNull('dons.deleted_at')
            ->whereBetween('dons.date', [$start, $end])
            ->whereNotNull('dons.seance')
            ->whereIn('dons.operation_id', $operationIds)
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', 'dons.seance', DB::raw('SUM(dons.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', 'dons.seance')
            ->get());

        $flat = [];
        foreach ($map as $seanceMap) {
            foreach ($seanceMap as $entry) {
                $flat[] = $entry;
            }
        }

        return collect($flat)->map(fn ($row) => (object) $row);
    }

    /**
     * Budget alloué par sous-catégorie pour un exercice.
     *
     * @return array<int, float> [sous_categorie_id => montant_prevu]
     */
    private function fetchBudgetMap(int $exercice): array
    {
        return DB::table('budget_lines')
            ->where('exercice', $exercice)
            ->select('sous_categorie_id', DB::raw('SUM(montant_prevu) as budget'))
            ->groupBy('sous_categorie_id')
            ->get()
            ->keyBy('sous_categorie_id')
            ->map(fn ($row) => (float) $row->budget)
            ->all();
    }

    // ── Private helpers — construction de la hiérarchie ───────────────────────

    /**
     * Construit la hiérarchie complète avec montant_n, montant_n1, budget (onglet 1).
     *
     * @param  Collection<int, object>  $flatN  Rows exercice N
     * @param  Collection<int, object>  $flatN1  Rows exercice N-1
     * @param  array<int, float>  $budgetMap
     * @return list<array>
     */
    private function buildHierarchyFull(Collection $flatN, Collection $flatN1, array $budgetMap): array
    {
        // Map intermédiaire keyed by sous_categorie_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flatN as $row) {
            $scId = (int) $row->sous_categorie_id;
            $map[$scId] = [
                'categorie_id' => (int) $row->categorie_id,
                'categorie_nom' => $row->categorie_nom,
                'sous_categorie_nom' => $row->sous_categorie_nom,
                'montant_n' => (float) $row->montant,
                'montant_n1' => null,
                'budget' => $budgetMap[$scId] ?? null,
            ];
        }

        foreach ($flatN1 as $row) {
            $scId = (int) $row->sous_categorie_id;
            if (isset($map[$scId])) {
                $map[$scId]['montant_n1'] = (float) $row->montant;
            } else {
                // Sous-cat présente en N-1 mais pas en N
                $map[$scId] = [
                    'categorie_id' => (int) $row->categorie_id,
                    'categorie_nom' => $row->categorie_nom,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'montant_n' => 0.0,
                    'montant_n1' => (float) $row->montant,
                    'budget' => $budgetMap[$scId] ?? null,
                ];
            }
        }

        return $this->groupByCategorie($map, true);
    }

    /**
     * Construit la hiérarchie simple avec montant uniquement (onglet 2).
     *
     * @param  Collection<int, object>  $flat
     * @return list<array>
     */
    private function buildHierarchySimple(Collection $flat): array
    {
        $map = [];
        foreach ($flat as $row) {
            $scId = (int) $row->sous_categorie_id;
            $map[$scId] = [
                'categorie_id' => (int) $row->categorie_id,
                'categorie_nom' => $row->categorie_nom,
                'sous_categorie_nom' => $row->sous_categorie_nom,
                'montant' => (float) $row->montant,
            ];
        }

        return $this->groupByCategorie($map, false);
    }

    /**
     * Construit la hiérarchie avec colonnes séances (onglet 3).
     *
     * @param  Collection<int, object>  $flat
     * @param  list<int>  $allSeances
     * @return list<array>
     */
    private function buildHierarchySeances(Collection $flat, array $allSeances): array
    {
        // Map keyed by sous_categorie_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flat as $row) {
            $scId = (int) $row->sous_categorie_id;
            $seance = (int) $row->seance;

            if (! isset($map[$scId])) {
                $map[$scId] = [
                    'categorie_id' => (int) $row->categorie_id,
                    'categorie_nom' => $row->categorie_nom,
                    'sous_categorie_nom' => $row->sous_categorie_nom,
                    'seances' => [],
                    'total' => 0.0,
                ];
            }
            $map[$scId]['seances'][$seance] = ($map[$scId]['seances'][$seance] ?? 0.0) + (float) $row->montant;
            $map[$scId]['total'] += (float) $row->montant;
        }

        // Group by catégorie
        /** @var array<int, array> */
        $categories = [];
        foreach ($map as $scId => $sc) {
            $catId = $sc['categorie_id'];
            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'categorie_id' => $catId,
                    'label' => $sc['categorie_nom'],
                    'seances' => array_fill_keys($allSeances, 0.0),
                    'total' => 0.0,
                    'sous_categories' => [],
                ];
            }

            // Pad sous-catégorie séances avec 0.0 pour séances manquantes
            $scSeances = [];
            foreach ($allSeances as $s) {
                $scSeances[$s] = $sc['seances'][$s] ?? 0.0;
            }

            foreach ($allSeances as $s) {
                $categories[$catId]['seances'][$s] += $scSeances[$s];
            }
            $categories[$catId]['total'] += $sc['total'];

            $categories[$catId]['sous_categories'][] = [
                'sous_categorie_id' => $scId,
                'label' => $sc['sous_categorie_nom'],
                'seances' => $scSeances,
                'total' => $sc['total'],
            ];
        }

        usort($categories, fn ($a, $b) => strcmp($a['label'], $b['label']));
        foreach ($categories as &$cat) {
            usort($cat['sous_categories'], fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return array_values($categories);
    }

    /**
     * Regroupe la map plate en hiérarchie catégorie → sous-catégories.
     *
     * @param  array<int, array>  $map  Keyed by sous_categorie_id
     * @param  bool  $withN1Budget  Inclure montant_n1 et budget dans le retour
     * @return list<array>
     */
    private function groupByCategorie(array $map, bool $withN1Budget): array
    {
        /** @var array<int, array> */
        $categories = [];

        foreach ($map as $scId => $sc) {
            $catId = $sc['categorie_id'];

            if (! isset($categories[$catId])) {
                $cat = [
                    'categorie_id' => $catId,
                    'label' => $sc['categorie_nom'],
                    'sous_categories' => [],
                ];
                if ($withN1Budget) {
                    $cat['montant_n'] = 0.0;
                    $cat['montant_n1'] = null;
                    $cat['budget'] = null;
                } else {
                    $cat['montant'] = 0.0;
                }
                $categories[$catId] = $cat;
            }

            if ($withN1Budget) {
                $categories[$catId]['montant_n'] += $sc['montant_n'];
                if ($sc['montant_n1'] !== null) {
                    $categories[$catId]['montant_n1'] = ($categories[$catId]['montant_n1'] ?? 0.0) + $sc['montant_n1'];
                }
                if ($sc['budget'] !== null) {
                    $categories[$catId]['budget'] = ($categories[$catId]['budget'] ?? 0.0) + $sc['budget'];
                }
                $categories[$catId]['sous_categories'][] = [
                    'sous_categorie_id' => $scId,
                    'label' => $sc['sous_categorie_nom'],
                    'montant_n' => $sc['montant_n'],
                    'montant_n1' => $sc['montant_n1'],
                    'budget' => $sc['budget'],
                ];
            } else {
                $categories[$catId]['montant'] += $sc['montant'];
                $categories[$catId]['sous_categories'][] = [
                    'sous_categorie_id' => $scId,
                    'label' => $sc['sous_categorie_nom'],
                    'montant' => $sc['montant'],
                ];
            }
        }

        usort($categories, fn ($a, $b) => strcmp($a['label'], $b['label']));
        foreach ($categories as &$cat) {
            usort($cat['sous_categories'], fn ($a, $b) => strcmp($a['label'], $b['label']));
        }

        return array_values($categories);
    }
}
