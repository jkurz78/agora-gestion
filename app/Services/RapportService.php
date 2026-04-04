<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\Exercice;
use App\Models\Transaction;
use App\Models\VirementInterne;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
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
     * Optionnellement ventilé par séances et/ou par tiers.
     *
     * @param  array<int>  $operationIds
     * @return array{charges: list<array>, produits: list<array>, seances?: list<int>}
     */
    public function compteDeResultatOperations(
        int $exercice,
        array $operationIds,
        bool $parSeances = false,
        bool $parTiers = false,
    ): array {
        [$start, $end] = $this->exerciceDates($exercice);

        if (! $parSeances && ! $parTiers) {
            $charges = $this->fetchDepenseRows($start, $end, $operationIds);
            $produits = $this->fetchProduitsRows($start, $end, $exercice, $operationIds);

            return [
                'charges' => $this->buildHierarchySimple($charges),
                'produits' => $this->buildHierarchySimple($produits),
            ];
        }

        $chargesMap = $this->fetchOperationRows('depense', $start, $end, $operationIds, $parSeances, $parTiers);
        $produitsMap = $this->fetchOperationRows('recette', $start, $end, $operationIds, $parSeances, $parTiers);

        $allSeances = [];
        if ($parSeances) {
            $allSeances = collect(array_merge(array_values($chargesMap), array_values($produitsMap)))
                ->pluck('seance')
                ->unique()
                ->map(fn ($s) => (int) $s)
                ->sort()
                ->values()
                ->all();
        }

        $result = [
            'charges' => $this->buildHierarchyOperations($chargesMap, $parSeances, $parTiers, $allSeances),
            'produits' => $this->buildHierarchyOperations($produitsMap, $parSeances, $parTiers, $allSeances),
        ];

        if ($parSeances) {
            $result['seances'] = $allSeances;
        }

        return $result;
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

        // 0 = "Hors séance" — trié en dernier
        $allSeances = collect($chargeRows)
            ->merge($produitsRows)
            ->pluck('seance')
            ->unique()
            ->map(fn ($s) => (int) $s)
            ->sortBy(fn ($s) => $s === 0 ? PHP_INT_MAX : $s)
            ->values()
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
     * @param  array<int>|null  $operationIds
     * @param  array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,montant:float}>  $map
     */
    private function accumulerDepensesResolues(string $start, string $end, ?array $operationIds, array &$map): void
    {
        // Partie 1 : lignes sans affectations
        $q1 = DB::table('transaction_lignes')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as d', 'd.id', '=', 'transaction_lignes.transaction_id')
            ->where('d.type', 'depense')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereNull('tla.id')
            ->whereBetween('d.date', [$start, $end])
            ->select([
                'c.id as categorie_id', 'c.nom as categorie_nom',
                'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
                DB::raw('SUM(transaction_lignes.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

        if ($operationIds !== null) {
            $q1->whereIn('transaction_lignes.operation_id', $operationIds);
        }

        // Partie 2 : lignes avec affectations (utiliser affectation.montant et affectation.operation_id)
        $q2 = DB::table('transaction_ligne_affectations as tla')
            ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla.transaction_ligne_id')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as d', 'd.id', '=', 'transaction_lignes.transaction_id')
            ->where('d.type', 'depense')
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('d.deleted_at')
            ->whereBetween('d.date', [$start, $end])
            ->select([
                'c.id as categorie_id', 'c.nom as categorie_nom',
                'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
                DB::raw('SUM(tla.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');

        if ($operationIds !== null) {
            $q2->whereIn('tla.operation_id', $operationIds);
        }

        foreach ([$q1->get(), $q2->get()] as $rows) {
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
        }
    }

    /**
     * Agrégation des produits (recettes via transaction_lignes).
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre
     * @return Collection<int, object>
     */
    private function fetchProduitsRows(string $start, string $end, int $exercice, ?array $operationIds = null): Collection
    {
        $map = [];
        $this->accumulerRecettesResolues($start, $end, $operationIds, $map);

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
     * @param  array<int>  $operationIds
     * @param  array<int, array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,seance:int,montant:float}>>  $map
     */
    private function accumulerDepensesSeancesResolues(string $start, string $end, array $operationIds, array &$map): void
    {
        // Lignes sans affectations (seance=NULL → 0 = "Hors séance")
        $rows1 = DB::table('transaction_lignes')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as d', 'd.id', '=', 'transaction_lignes.transaction_id')
            ->where('d.type', 'depense')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('d.deleted_at')
            ->whereNull('tla.id')
            ->whereIn('transaction_lignes.operation_id', $operationIds)
            ->whereBetween('d.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('COALESCE(transaction_lignes.seance, 0) as seance'), DB::raw('SUM(transaction_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', DB::raw('COALESCE(transaction_lignes.seance, 0)'))
            ->get();

        // Lignes avec affectations (seance=NULL → 0 = "Hors séance")
        $rows2 = DB::table('transaction_ligne_affectations as tla')
            ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla.transaction_ligne_id')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as d', 'd.id', '=', 'transaction_lignes.transaction_id')
            ->where('d.type', 'depense')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('d.deleted_at')
            ->whereNotNull('tla.operation_id')
            ->whereIn('tla.operation_id', $operationIds)
            ->whereBetween('d.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('COALESCE(tla.seance, 0) as seance'), DB::raw('SUM(tla.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', DB::raw('COALESCE(tla.seance, 0)'))
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
     * @param  array<int>|null  $operationIds
     * @param  array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,montant:float}>  $map
     */
    private function accumulerRecettesResolues(string $start, string $end, ?array $operationIds, array &$map): void
    {
        // Partie 1 : lignes sans affectations
        $rq1 = DB::table('transaction_lignes')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as r', 'r.id', '=', 'transaction_lignes.transaction_id')
            ->where('r.type', 'recette')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('r.deleted_at')
            ->whereNull('tla.id')
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(transaction_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $rq1->whereIn('transaction_lignes.operation_id', $operationIds);
        }

        // Partie 2 : lignes avec affectations
        $rq2 = DB::table('transaction_ligne_affectations as tla')
            ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla.transaction_ligne_id')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as r', 'r.id', '=', 'transaction_lignes.transaction_id')
            ->where('r.type', 'recette')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('r.deleted_at')
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('SUM(tla.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom');
        if ($operationIds !== null) {
            $rq2->whereIn('tla.operation_id', $operationIds);
        }

        foreach ([$rq1->get(), $rq2->get()] as $rows) {
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
        }
    }

    /**
     * @param  array<int>  $operationIds
     * @param  array<int, array<int, array{categorie_id:int,categorie_nom:string,sous_categorie_id:int,sous_categorie_nom:string,seance:int,montant:float}>>  $map
     */
    private function accumulerRecettesSeancesResolues(string $start, string $end, array $operationIds, array &$map): void
    {
        // Lignes sans affectations (seance=NULL → 0 = "Hors séance")
        $rows1 = DB::table('transaction_lignes')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as r', 'r.id', '=', 'transaction_lignes.transaction_id')
            ->where('r.type', 'recette')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('r.deleted_at')
            ->whereNull('tla.id')
            ->whereIn('transaction_lignes.operation_id', $operationIds)
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('COALESCE(transaction_lignes.seance, 0) as seance'), DB::raw('SUM(transaction_lignes.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', DB::raw('COALESCE(transaction_lignes.seance, 0)'))
            ->get();

        // Lignes avec affectations (seance=NULL → 0 = "Hors séance")
        $rows2 = DB::table('transaction_ligne_affectations as tla')
            ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla.transaction_ligne_id')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as r', 'r.id', '=', 'transaction_lignes.transaction_id')
            ->where('r.type', 'recette')
            ->whereNull('transaction_lignes.deleted_at')->whereNull('r.deleted_at')
            ->whereNotNull('tla.operation_id')
            ->whereIn('tla.operation_id', $operationIds)
            ->whereBetween('r.date', [$start, $end])
            ->select(['c.id as categorie_id', 'c.nom as categorie_nom', 'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom', DB::raw('COALESCE(tla.seance, 0) as seance'), DB::raw('SUM(tla.montant) as montant')])
            ->groupBy('c.id', 'c.nom', 'sc.id', 'sc.nom', DB::raw('COALESCE(tla.seance, 0)'))
            ->get();

        foreach ([$rows1, $rows2] as $rows) {
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
        }
    }

    /**
     * Agrégation des produits par séance (recettes via transaction_lignes).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        $map = [];
        $this->accumulerRecettesSeancesResolues($start, $end, $operationIds, $map);

        $flat = [];
        foreach ($map as $seanceMap) {
            foreach ($seanceMap as $entry) {
                $flat[] = $entry;
            }
        }

        return collect($flat)->map(fn ($row) => (object) $row);
    }

    /**
     * Construit 2 query builders (sans/avec affectations) avec SELECT/GROUP BY dynamiques.
     *
     * @param  array<int>  $operationIds
     * @return array{Builder, Builder}
     */
    private function buildOperationQueries(
        string $type,
        string $start,
        string $end,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
    ): array {
        $baseCols = [
            'c.id as categorie_id', 'c.nom as categorie_nom',
            'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
        ];
        $baseGroup = ['c.id', 'c.nom', 'sc.id', 'sc.nom'];

        if ($withTiers) {
            $baseCols = array_merge($baseCols, [
                DB::raw('COALESCE(tx.tiers_id, 0) as tiers_id'),
                DB::raw("COALESCE(t.type, '') as tiers_type"),
                DB::raw("COALESCE(t.nom, '') as tiers_nom"),
                DB::raw("COALESCE(t.prenom, '') as tiers_prenom"),
                DB::raw("COALESCE(t.entreprise, '') as tiers_entreprise"),
            ]);
            $baseGroup = array_merge($baseGroup, ['tx.tiers_id', 't.type', 't.nom', 't.prenom', 't.entreprise']);
        }

        // Q1 : lignes sans affectations
        $q1Cols = $baseCols;
        $q1Group = $baseGroup;
        if ($withSeance) {
            $q1Cols[] = DB::raw('COALESCE(transaction_lignes.seance, 0) as seance');
            $q1Group[] = DB::raw('COALESCE(transaction_lignes.seance, 0)');
        }
        $q1Cols[] = DB::raw('SUM(transaction_lignes.montant) as montant');

        $q1 = DB::table('transaction_lignes')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as tx', 'tx.id', '=', 'transaction_lignes.transaction_id')
            ->where('tx.type', $type)
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'transaction_lignes.id')
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('tla.id')
            ->whereIn('transaction_lignes.operation_id', $operationIds)
            ->whereBetween('tx.date', [$start, $end])
            ->select($q1Cols)
            ->groupBy($q1Group);

        if ($withTiers) {
            $q1->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id');
        }

        // Q2 : lignes avec affectations
        $q2Cols = $baseCols;
        $q2Group = $baseGroup;
        if ($withSeance) {
            $q2Cols[] = DB::raw('COALESCE(tla2.seance, 0) as seance');
            $q2Group[] = DB::raw('COALESCE(tla2.seance, 0)');
        }
        $q2Cols[] = DB::raw('SUM(tla2.montant) as montant');

        $q2 = DB::table('transaction_ligne_affectations as tla2')
            ->join('transaction_lignes', 'transaction_lignes.id', '=', 'tla2.transaction_ligne_id')
            ->join('sous_categories as sc', 'transaction_lignes.sous_categorie_id', '=', 'sc.id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('transactions as tx', 'tx.id', '=', 'transaction_lignes.transaction_id')
            ->where('tx.type', $type)
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereIn('tla2.operation_id', $operationIds)
            ->whereBetween('tx.date', [$start, $end])
            ->select($q2Cols)
            ->groupBy($q2Group);

        if ($withTiers) {
            $q2->leftJoin('tiers as t', 't.id', '=', 'tx.tiers_id');
        }

        return [$q1, $q2];
    }

    /**
     * Exécute les requêtes et accumule dans une map plate à clé composite.
     *
     * @param  array<int>  $operationIds
     * @return array<string, array>
     */
    private function fetchOperationRows(
        string $type,
        string $start,
        string $end,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
    ): array {
        [$q1, $q2] = $this->buildOperationQueries($type, $start, $end, $operationIds, $withSeance, $withTiers);

        $map = [];
        foreach ([$q1->get(), $q2->get()] as $rows) {
            foreach ($rows as $row) {
                $key = (string) $row->sous_categorie_id;
                if ($withTiers) {
                    $key .= '_'.$row->tiers_id;
                }
                if ($withSeance) {
                    $key .= '_'.$row->seance;
                }

                if (isset($map[$key])) {
                    $map[$key]['montant'] += (float) $row->montant;
                } else {
                    $entry = [
                        'categorie_id' => (int) $row->categorie_id,
                        'categorie_nom' => $row->categorie_nom,
                        'sous_categorie_id' => (int) $row->sous_categorie_id,
                        'sous_categorie_nom' => $row->sous_categorie_nom,
                        'montant' => (float) $row->montant,
                    ];
                    if ($withSeance) {
                        $entry['seance'] = (int) $row->seance;
                    }
                    if ($withTiers) {
                        $entry['tiers_id'] = (int) $row->tiers_id;
                        $entry['tiers_type'] = $row->tiers_type !== '' ? $row->tiers_type : null;
                        $entry['tiers_nom'] = $row->tiers_nom !== '' ? $row->tiers_nom : null;
                        $entry['tiers_prenom'] = $row->tiers_prenom !== '' ? $row->tiers_prenom : null;
                        $entry['tiers_entreprise'] = $row->tiers_entreprise !== '' ? $row->tiers_entreprise : null;
                    }
                    $map[$key] = $entry;
                }
            }
        }

        return $map;
    }

    /**
     * Construit la hiérarchie imbriquée (cat -> sous-cat -> tiers optionnel) avec montants simples ou ventilés par séance.
     *
     * @param  array<string, array>  $map
     * @param  list<int>  $allSeances
     * @return list<array>
     */
    private function buildHierarchyOperations(array $map, bool $withSeance, bool $withTiers, array $allSeances = []): array
    {
        $categories = [];

        foreach ($map as $entry) {
            $catId = $entry['categorie_id'];
            $scId = $entry['sous_categorie_id'];

            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'categorie_id' => $catId,
                    'label' => $entry['categorie_nom'],
                    'sous_categories_map' => [],
                ];
                if ($withSeance) {
                    $categories[$catId]['seances'] = array_fill_keys($allSeances, 0.0);
                    $categories[$catId]['total'] = 0.0;
                } else {
                    $categories[$catId]['montant'] = 0.0;
                }
            }

            if (! isset($categories[$catId]['sous_categories_map'][$scId])) {
                $scEntry = ['sous_categorie_id' => $scId, 'label' => $entry['sous_categorie_nom']];
                if ($withSeance) {
                    $scEntry['seances'] = array_fill_keys($allSeances, 0.0);
                    $scEntry['total'] = 0.0;
                } else {
                    $scEntry['montant'] = 0.0;
                }
                if ($withTiers) {
                    $scEntry['tiers_map'] = [];
                }
                $categories[$catId]['sous_categories_map'][$scId] = $scEntry;
            }

            $montant = $entry['montant'];

            if ($withTiers) {
                $tiersId = $entry['tiers_id'];
                if (! isset($categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId])) {
                    $tEntry = [
                        'tiers_id' => $tiersId,
                        'label' => $this->formatTiersLabel($entry),
                        'type' => $tiersId === 0 ? null : $entry['tiers_type'],
                    ];
                    if ($withSeance) {
                        $tEntry['seances'] = array_fill_keys($allSeances, 0.0);
                        $tEntry['total'] = 0.0;
                    } else {
                        $tEntry['montant'] = 0.0;
                    }
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId] = $tEntry;
                }
                if ($withSeance) {
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['seances'][$entry['seance']] += $montant;
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['total'] += $montant;
                } else {
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['montant'] += $montant;
                }
            }

            if ($withSeance) {
                $seance = $entry['seance'];
                $categories[$catId]['sous_categories_map'][$scId]['seances'][$seance] += $montant;
                $categories[$catId]['sous_categories_map'][$scId]['total'] += $montant;
                $categories[$catId]['seances'][$seance] += $montant;
                $categories[$catId]['total'] += $montant;
            } else {
                $categories[$catId]['sous_categories_map'][$scId]['montant'] += $montant;
                $categories[$catId]['montant'] += $montant;
            }
        }

        $result = [];
        foreach ($categories as $cat) {
            $scs = [];
            foreach ($cat['sous_categories_map'] as $sc) {
                if ($withTiers) {
                    $tiers = array_values($sc['tiers_map']);
                    usort($tiers, fn ($a, $b) => strcmp($a['label'], $b['label']));
                    $sc['tiers'] = $tiers;
                    unset($sc['tiers_map']);
                }
                $scs[] = $sc;
            }
            usort($scs, fn ($a, $b) => strcmp($a['label'], $b['label']));
            $cat['sous_categories'] = $scs;
            unset($cat['sous_categories_map']);
            $result[] = $cat;
        }
        usort($result, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $result;
    }

    /**
     * Formate le label d'un tiers pour l'affichage.
     */
    private function formatTiersLabel(array $entry): string
    {
        if ($entry['tiers_id'] === 0) {
            return '(sans tiers)';
        }
        if ($entry['tiers_type'] === 'entreprise') {
            return ($entry['tiers_entreprise'] !== null && $entry['tiers_entreprise'] !== '')
                ? $entry['tiers_entreprise']
                : mb_strtoupper($entry['tiers_nom'] ?? '');
        }
        $nom = mb_strtoupper($entry['tiers_nom'] ?? '');
        $prenom = $entry['tiers_prenom'] ?? '';

        return trim(($prenom !== '' ? $prenom.' ' : '').$nom);
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

    // ── Flux de trésorerie ───────────────────────────────────────────────────

    /**
     * État de flux de trésorerie consolidé.
     *
     * @return array{exercice: array, synthese: array, rapprochement: array, mensuel: list<array>, ecritures_non_pointees: list<array>}
     */
    public function fluxTresorerie(int $exercice): array
    {
        $soldeService = app(SoldeService::class);
        [$start, $end] = $this->exerciceDates($exercice);

        // --- Exercice info ---
        $exerciceModel = Exercice::where('annee', $exercice)->first();
        $exerciceInfo = [
            'annee' => $exercice,
            'label' => $exercice.'-'.($exercice + 1),
            'date_debut' => $start,
            'date_fin' => $end,
            'is_cloture' => $exerciceModel?->isCloture() ?? false,
            'date_cloture' => $exerciceModel?->date_cloture?->format('d/m/Y'),
        ];

        // --- Solde d'ouverture consolidé (tous comptes, y compris système) ---
        $comptes = CompteBancaire::all();
        $soldeOuverture = 0.0;

        foreach ($comptes as $compte) {
            $soldeActuelCompte = $soldeService->solde($compte);
            $recettesCompte = (float) $compte->recettes()->forExercice($exercice)->sum('montant_total');
            $depensesCompte = (float) $compte->depenses()->forExercice($exercice)->sum('montant_total');
            $virementsIn = (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->forExercice($exercice)->sum('montant');
            $virementsOut = (float) VirementInterne::where('compte_source_id', $compte->id)
                ->forExercice($exercice)->sum('montant');

            $soldeOuverture += $soldeActuelCompte - $recettesCompte + $depensesCompte - $virementsIn + $virementsOut;
        }
        $soldeOuverture = round($soldeOuverture, 2);

        // --- Totaux consolidés (virements s'annulent) ---
        $totalRecettes = round((float) Transaction::where('type', 'recette')->forExercice($exercice)->sum('montant_total'), 2);
        $totalDepenses = round((float) Transaction::where('type', 'depense')->forExercice($exercice)->sum('montant_total'), 2);
        $variation = round($totalRecettes - $totalDepenses, 2);
        $soldeTheorique = round($soldeOuverture + $variation, 2);

        // --- Rapprochement (comptes réels uniquement, pas les comptes système) ---
        $comptesReelsIds = CompteBancaire::where('est_systeme', false)->pluck('id');
        $nonPointees = Transaction::whereNull('rapprochement_id')
            ->whereIn('compte_id', $comptesReelsIds)
            ->whereBetween('date', [$start, $end])
            ->selectRaw("
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as total_recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as total_depenses,
                SUM(CASE WHEN type = 'recette' THEN 1 ELSE 0 END) as nb_recettes,
                SUM(CASE WHEN type = 'depense' THEN 1 ELSE 0 END) as nb_depenses
            ")
            ->first();

        $recettesNonPointees = round((float) ($nonPointees->total_recettes ?? 0), 2);
        $depensesNonPointees = round((float) ($nonPointees->total_depenses ?? 0), 2);
        $nbRecettesNonPointees = (int) ($nonPointees->nb_recettes ?? 0);
        $nbDepensesNonPointees = (int) ($nonPointees->nb_depenses ?? 0);

        // --- Comptes système (créances à recevoir, remise bancaire, etc.) ---
        $comptesSysteme = [];
        $totalComptesSysteme = 0.0;
        foreach (CompteBancaire::where('est_systeme', true)->orderBy('nom')->get() as $cs) {
            $soldeCs = $soldeService->solde($cs);
            if (abs($soldeCs) < 0.01) {
                continue; // ne pas afficher les comptes système à zéro
            }

            $ecrituresCs = Transaction::where('compte_id', $cs->id)
                ->whereBetween('date', [$start, $end])
                ->with('tiers')
                ->orderBy('date')
                ->get()
                ->map(fn (Transaction $t) => [
                    'numero_piece' => $t->numero_piece,
                    'date' => $t->date->format('d/m/Y'),
                    'tiers' => $t->tiers?->displayName() ?? '—',
                    'libelle' => $t->libelle,
                    'type' => $t->type->value,
                    'montant' => (float) $t->montant_total,
                ])
                ->values()
                ->all();

            $comptesSysteme[] = [
                'nom' => $cs->nom,
                'solde' => $soldeCs,
                'nb_ecritures' => count($ecrituresCs),
                'ecritures' => $ecrituresCs,
            ];
            $totalComptesSysteme += $soldeCs;
        }
        $totalComptesSysteme = round($totalComptesSysteme, 2);

        $soldeReel = round($soldeTheorique - $recettesNonPointees + $depensesNonPointees - $totalComptesSysteme, 2);

        // --- Ventilation mensuelle ---
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $yearExpr = $isSqlite ? "CAST(strftime('%Y', date) AS INTEGER)" : 'YEAR(date)';
        $monthExpr = $isSqlite ? "CAST(strftime('%m', date) AS INTEGER)" : 'MONTH(date)';

        $mensuelRows = Transaction::forExercice($exercice)
            ->selectRaw("
                {$yearExpr} as annee, {$monthExpr} as mois_num,
                SUM(CASE WHEN type = 'recette' THEN montant_total ELSE 0 END) as recettes,
                SUM(CASE WHEN type = 'depense' THEN montant_total ELSE 0 END) as depenses
            ")
            ->groupByRaw("{$yearExpr}, {$monthExpr}")
            ->get()
            ->keyBy(fn ($row) => $row->annee.'-'.str_pad((string) $row->mois_num, 2, '0', STR_PAD_LEFT));

        $mensuel = [];
        $cumul = $soldeOuverture;
        for ($i = 0; $i < 12; $i++) {
            $moisNum = (($i + 8) % 12) + 1; // 9,10,11,12,1,2,3,4,5,6,7,8
            $annee = $moisNum >= 9 ? $exercice : $exercice + 1;
            $key = $annee.'-'.str_pad((string) $moisNum, 2, '0', STR_PAD_LEFT);

            $recettes = round((float) ($mensuelRows[$key]->recettes ?? 0), 2);
            $depenses = round((float) ($mensuelRows[$key]->depenses ?? 0), 2);
            $solde = round($recettes - $depenses, 2);
            $cumul = round($cumul + $solde, 2);

            $moisLabel = ucfirst(Carbon::create($annee, $moisNum, 1)->translatedFormat('F Y'));

            $mensuel[] = [
                'mois' => $moisLabel,
                'recettes' => $recettes,
                'depenses' => $depenses,
                'solde' => $solde,
                'cumul' => $cumul,
            ];
        }

        // --- Liste écritures non pointées (pour PDF lot 4, comptes réels uniquement) ---
        $ecrituresNonPointees = Transaction::whereNull('rapprochement_id')
            ->whereIn('compte_id', $comptesReelsIds)
            ->whereBetween('date', [$start, $end])
            ->with('tiers')
            ->orderBy('date')
            ->get()
            ->map(fn (Transaction $t) => [
                'numero_piece' => $t->numero_piece,
                'date' => $t->date->format('d/m/Y'),
                'tiers' => $t->tiers?->displayName() ?? '—',
                'libelle' => $t->libelle,
                'type' => $t->type->value,
                'montant' => (float) $t->montant_total,
            ])
            ->values()
            ->all();

        return [
            'exercice' => $exerciceInfo,
            'synthese' => [
                'solde_ouverture' => $soldeOuverture,
                'total_recettes' => $totalRecettes,
                'total_depenses' => $totalDepenses,
                'variation' => $variation,
                'solde_theorique' => $soldeTheorique,
            ],
            'rapprochement' => [
                'solde_theorique' => $soldeTheorique,
                'recettes_non_pointees' => $recettesNonPointees,
                'nb_recettes_non_pointees' => $nbRecettesNonPointees,
                'depenses_non_pointees' => $depensesNonPointees,
                'nb_depenses_non_pointees' => $nbDepensesNonPointees,
                'comptes_systeme' => $comptesSysteme,
                'total_comptes_systeme' => $totalComptesSysteme,
                'solde_reel' => $soldeReel,
            ],
            'mensuel' => $mensuel,
            'ecritures_non_pointees' => $ecrituresNonPointees,
        ];
    }
}
