<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Operation;
use App\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class CompteResultatBuilder
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
        bool $previsionnel = false,
        bool $parOperations = false,
    ): array {
        [$start, $end] = $this->exerciceDates($exercice);

        $projMatrices = $previsionnel ? $this->computeProjections($start, $end, $operationIds) : null;

        // ── Un seul fetch, toutes dimensions ─────────────────────────────────
        $chargesMap = $this->fetchOperationRows('depense', $start, $end, $operationIds, $parSeances, $parTiers, $parOperations);
        $produitsMap = $this->fetchOperationRows('recette', $start, $end, $operationIds, $parSeances, $parTiers, $parOperations);

        $allSeances = [];
        if ($parSeances) {
            $allSeances = collect(array_merge(array_values($chargesMap), array_values($produitsMap)))
                ->pluck('seance')
                ->unique()
                ->map(fn ($s) => (int) $s)
                ->sort()
                ->values()
                ->all();

            if ($previsionnel) {
                $prevSeances = DB::table('seances')
                    ->whereIn('operation_id', $operationIds)
                    ->when(TenantContext::hasBooted(), fn ($q) => $q->where('association_id', TenantContext::currentId()))
                    ->pluck('numero')
                    ->unique()
                    ->map(fn ($s) => (int) $s)
                    ->all();

                $allSeances = collect(array_merge($allSeances, $prevSeances))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        $result = [
            'charges' => $this->buildUnifiedHierarchy($chargesMap, $parSeances, $parTiers, $parOperations, $allSeances, $operationIds),
            'produits' => $this->buildUnifiedHierarchy($produitsMap, $parSeances, $parTiers, $parOperations, $allSeances, $operationIds),
        ];

        if ($parSeances) {
            $result['seances'] = $allSeances;
        }

        if ($parOperations) {
            $result['operation_names'] = Operation::whereIn('id', $operationIds)
                ->pluck('nom', 'id')
                ->all();
        }

        if ($parSeances && $parOperations) {
            $seancesParOp = DB::table('seances')
                ->whereIn('operation_id', $operationIds)
                ->when(TenantContext::hasBooted(), fn ($q) => $q->where('association_id', TenantContext::currentId()))
                ->select('operation_id', 'numero')
                ->orderBy('numero')
                ->get()
                ->groupBy('operation_id')
                ->map(fn ($rows) => $rows->pluck('numero')->map(fn ($n) => (int) $n)->values()->all())
                ->all();

            foreach (array_merge(array_values($chargesMap), array_values($produitsMap)) as $entry) {
                if (isset($entry['seance'], $entry['operation_id'])) {
                    $s = (int) $entry['seance'];
                    $eOpId = (int) $entry['operation_id'];
                    if (! isset($seancesParOp[$eOpId])) {
                        $seancesParOp[$eOpId] = [];
                    }
                    if (! in_array($s, $seancesParOp[$eOpId], true)) {
                        $seancesParOp[$eOpId][] = $s;
                    }
                }
            }

            $hasHorsSeance = collect(array_merge(array_values($chargesMap), array_values($produitsMap)))
                ->contains(fn ($entry) => ((int) ($entry['seance'] ?? -1)) === 0);
            if ($hasHorsSeance || ($previsionnel && in_array(0, $allSeances, true))) {
                foreach ($operationIds as $opId) {
                    if (! isset($seancesParOp[$opId])) {
                        $seancesParOp[$opId] = [];
                    }
                    if (! in_array(0, $seancesParOp[$opId], true)) {
                        array_unshift($seancesParOp[$opId], 0);
                    }
                }
            }

            foreach ($operationIds as $opId) {
                if (! isset($seancesParOp[$opId])) {
                    $seancesParOp[$opId] = [];
                }
                sort($seancesParOp[$opId]);
            }

            $result['seances_par_operation'] = $seancesParOp;
        }

        if ($previsionnel) {
            $prevC = $this->buildPrevisionsCharges($operationIds, $parSeances, $parTiers, $parOperations);
            $prevP = $this->buildPrevisionsProduits($operationIds, $parSeances, $parTiers, $parOperations);
            $result['charges'] = $this->mergePrevisionsIntoHierarchy($result['charges'], $prevC);
            $result['produits'] = $this->mergePrevisionsIntoHierarchy($result['produits'], $prevP);
            $result['previsions_charges'] = $prevC;
            $result['previsions_produits'] = $prevP;
            $result['proj_charges'] = $projMatrices['charges'];
            $result['proj_produits'] = $projMatrices['produits'];
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
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchDepenseRowsPD($start, $end, $operationIds);
        }

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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('d.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('d.association_id', TenantContext::currentId()))
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
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchProduitsRowsPD($start, $end, $operationIds);
        }

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
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchDepenseSeancesRowsPD($start, $end, $operationIds);
        }

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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('d.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('d.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('r.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('r.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('r.association_id', TenantContext::currentId()))
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('r.association_id', TenantContext::currentId()))
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
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchProduitsSeancesRowsPD($start, $end, $operationIds);
        }

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
        bool $withOperation = false,
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
        if ($withOperation) {
            $q1Cols[] = 'transaction_lignes.operation_id';
            $q1Group[] = 'transaction_lignes.operation_id';
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
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
        if ($withOperation) {
            $q2Cols[] = 'tla2.operation_id';
            $q2Group[] = 'tla2.operation_id';
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
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('tx.association_id', TenantContext::currentId()))
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
        bool $withOperation = false,
    ): array {
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchOperationRowsPD($type, $start, $end, $operationIds, $withSeance, $withTiers, $withOperation);
        }

        [$q1, $q2] = $this->buildOperationQueries($type, $start, $end, $operationIds, $withSeance, $withTiers, $withOperation);

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
                if ($withOperation) {
                    $key .= '_op'.$row->operation_id;
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
                    if ($withOperation) {
                        $entry['operation_id'] = (int) $row->operation_id;
                    }
                    $map[$key] = $entry;
                }
            }
        }

        return $map;
    }

    /**
     * @param  array<string, array>  $map  Flat entries from fetchOperationRows
     * @param  list<int>  $allSeances  Séance numbers (when $withSeance)
     * @param  array<int>  $operationIds  Operation IDs (when $withOperation)
     * @return list<array>
     */
    private function buildUnifiedHierarchy(
        array $map,
        bool $withSeance,
        bool $withTiers,
        bool $withOperation,
        array $allSeances = [],
        array $operationIds = [],
    ): array {
        $zeroOps = $withOperation ? array_fill_keys($operationIds, 0.0) : null;

        $categories = [];

        foreach ($map as $entry) {
            $catId = $entry['categorie_id'];
            $scId = $entry['sous_categorie_id'];
            $montant = (float) $entry['montant'];

            // ── Init catégorie ───────────────────────────────────────────────
            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'categorie_id' => $catId,
                    'label' => $entry['categorie_nom'],
                    'montant' => 0.0,
                    'sous_categories_map' => [],
                ];
                if ($withSeance) {
                    $categories[$catId]['seances'] = array_fill_keys($allSeances, 0.0);
                }
                if ($withOperation) {
                    $categories[$catId]['operations'] = $zeroOps;
                }
                if ($withSeance && $withOperation) {
                    $categories[$catId]['seance_operations'] = [];
                }
            }

            // ── Init sous-catégorie ──────────────────────────────────────────
            if (! isset($categories[$catId]['sous_categories_map'][$scId])) {
                $scEntry = [
                    'sous_categorie_id' => $scId,
                    'label' => $entry['sous_categorie_nom'],
                    'montant' => 0.0,
                ];
                if ($withSeance) {
                    $scEntry['seances'] = array_fill_keys($allSeances, 0.0);
                }
                if ($withOperation) {
                    $scEntry['operations'] = $zeroOps;
                }
                if ($withSeance && $withOperation) {
                    $scEntry['seance_operations'] = [];
                }
                if ($withTiers) {
                    $scEntry['tiers_map'] = [];
                }
                $categories[$catId]['sous_categories_map'][$scId] = $scEntry;
            }

            // ── Init & accumulate tiers ──────────────────────────────────────
            if ($withTiers) {
                $tiersId = $entry['tiers_id'];
                if (! isset($categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId])) {
                    $tEntry = [
                        'tiers_id' => $tiersId,
                        'label' => $this->formatTiersLabel($entry),
                        'type' => $tiersId === 0 ? null : $entry['tiers_type'],
                        'montant' => 0.0,
                    ];
                    if ($withSeance) {
                        $tEntry['seances'] = array_fill_keys($allSeances, 0.0);
                    }
                    if ($withOperation) {
                        $tEntry['operations'] = $zeroOps;
                    }
                    if ($withSeance && $withOperation) {
                        $tEntry['seance_operations'] = [];
                    }
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId] = $tEntry;
                }

                $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['montant'] += $montant;
                if ($withSeance) {
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['seances'][$entry['seance']] += $montant;
                }
                if ($withOperation) {
                    $opId = $entry['operation_id'];
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['operations'][$opId]
                        = ($categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['operations'][$opId] ?? 0.0) + $montant;
                }
                if ($withSeance && $withOperation) {
                    $seance = $entry['seance'];
                    $opId = $entry['operation_id'];
                    $categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['seance_operations'][$seance][$opId]
                        = ($categories[$catId]['sous_categories_map'][$scId]['tiers_map'][$tiersId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
                }
            }

            // ── Accumulate SC + cat ──────────────────────────────────────────
            $categories[$catId]['sous_categories_map'][$scId]['montant'] += $montant;
            $categories[$catId]['montant'] += $montant;

            if ($withSeance) {
                $seance = $entry['seance'];
                $categories[$catId]['sous_categories_map'][$scId]['seances'][$seance] += $montant;
                $categories[$catId]['seances'][$seance] += $montant;
            }
            if ($withOperation) {
                $opId = $entry['operation_id'];
                $categories[$catId]['sous_categories_map'][$scId]['operations'][$opId]
                    = ($categories[$catId]['sous_categories_map'][$scId]['operations'][$opId] ?? 0.0) + $montant;
                $categories[$catId]['operations'][$opId]
                    = ($categories[$catId]['operations'][$opId] ?? 0.0) + $montant;
            }
            if ($withSeance && $withOperation) {
                $seance = $entry['seance'];
                $opId = $entry['operation_id'];
                $categories[$catId]['sous_categories_map'][$scId]['seance_operations'][$seance][$opId]
                    = ($categories[$catId]['sous_categories_map'][$scId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
                $categories[$catId]['seance_operations'][$seance][$opId]
                    = ($categories[$catId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
            }
        }

        // ── Flatten & sort ───────────────────────────────────────────────────
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
     * En mode partie double, le compte de résultat agrège les montants par
     * compte_id (cf. fetchClasseRowsPD : sous_categorie_id = compte.id). La clé
     * budget est donc re-mappée sur compte_id pour rester alignée avec les rows
     * du rapport — sinon la colonne budget ne s'affiche jamais (clé legacy
     * sous_categorie_id ≠ compte_id du rapport).
     *
     * @return array<int, float> [sous_categorie_id (legacy) | compte_id (PD) => montant_prevu]
     */
    private function fetchBudgetMap(int $exercice): array
    {
        if (Config::get('compta.use_partie_double', false)) {
            return $this->fetchBudgetMapPD($exercice);
        }

        return DB::table('budget_lines')
            ->where('exercice', $exercice)
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('budget_lines.association_id', TenantContext::currentId()))
            ->select('sous_categorie_id', DB::raw('SUM(montant_prevu) as budget'))
            ->groupBy('sous_categorie_id')
            ->get()
            ->keyBy('sous_categorie_id')
            ->map(fn ($row) => (float) $row->budget)
            ->all();
    }

    /**
     * Budget alloué par COMPTE pour un exercice (mode partie double).
     *
     * Suit la même correspondance que le backfill compte_id (Step 36) :
     *   budget_lines.sous_categorie_id → sous_categories.code_cerfa → comptes.numero_pcg → comptes.id
     *
     * Plusieurs sous-catégories partageant un même code_cerfa se replient sur un
     * seul compte → leurs budgets sont sommés (cohérent avec l'agrégation des
     * montants par compte). Une sous-catégorie sans code_cerfa, ou dont le
     * code_cerfa ne correspond à aucun compte, est silencieusement ignorée
     * (pas de compte cible → pas de ligne dans le rapport PD non plus).
     *
     * @return array<int, float> [compte_id => montant_prevu]
     */
    private function fetchBudgetMapPD(int $exercice): array
    {
        return DB::table('budget_lines as bl')
            ->join('sous_categories as sc', 'sc.id', '=', 'bl.sous_categorie_id')
            ->join('comptes as cpt', function ($join): void {
                $join->on('cpt.numero_pcg', '=', 'sc.code_cerfa')
                    ->on('cpt.association_id', '=', 'bl.association_id');
            })
            ->where('bl.exercice', $exercice)
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('bl.association_id', TenantContext::currentId()))
            ->select('cpt.id as compte_id', DB::raw('SUM(bl.montant_prevu) as budget'))
            ->groupBy('cpt.id')
            ->get()
            ->keyBy('compte_id')
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

    // ── Path partie double (feature flag compta.use_partie_double = true) ────

    /**
     * Construit la requête DB pour les lignes PD d'une classe PCG donnée (6 ou 7).
     *
     * Lecture : transaction_lignes JOIN comptes (classe = $classe) + JOIN categories via comptes.categorie_id.
     * Filtre exercice via transactions.date (entre $start et $end).
     * Multi-tenant : filtre sur comptes.association_id (le JOIN garantit l'isolation).
     *
     * Agrégation :
     * - Classe 7 (recettes) : SUM(credit) - SUM(debit)
     * - Classe 6 (dépenses) : SUM(debit) - SUM(credit)
     *
     * NOTE — Affectations partielles (transaction_ligne_affectations) :
     *   Ce path PD lit tl.debit / tl.credit, c'est-à-dire le montant total de chaque ligne,
     *   indépendamment du découpage éventuel dans transaction_ligne_affectations.
     *   Pour compteDeResultat (sans filtre opération) :
     *     - Affectations complètes (SUM(tla.montant) = tl.montant) → total identique au mode legacy ✓
     *     - Affectations partielles (cas rare aujourd'hui) → PD peut surcompter vs legacy.
     *   Ce scénario sera couvert par PartieDoubleEquivalenceTest au Step 28.
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre opération
     * @return Collection<int, object> Colonnes : categorie_id, categorie_nom, sous_categorie_id, sous_categorie_nom, montant
     */
    private function fetchClasseRowsPD(string $start, string $end, int $classe, ?array $operationIds = null): Collection
    {
        $isSigne7 = $classe === 7;
        $montantExpr = $isSigne7
            ? DB::raw('SUM(tl.credit) - SUM(tl.debit) as montant')
            : DB::raw('SUM(tl.debit) - SUM(tl.credit) as montant');

        $q = DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as t', 'tl.transaction_id', '=', 't.id')
            ->leftJoin('categories as cat', 'c.categorie_id', '=', 'cat.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.date', [$start, $end])
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('c.association_id', TenantContext::currentId()))
            ->select([
                DB::raw('COALESCE(cat.id, 0) as categorie_id'),
                DB::raw("COALESCE(cat.nom, '(sans catégorie)') as categorie_nom"),
                // En mode PD, sous_categorie_id = compte_id (mapping transparent pour les builders hiérarchie)
                DB::raw('c.id as sous_categorie_id'),
                DB::raw('c.intitule as sous_categorie_nom'),
                $montantExpr,
            ])
            ->groupBy('c.id', 'c.intitule', 'cat.id', 'cat.nom');

        if ($operationIds !== null) {
            $q->whereIn('tl.operation_id', $operationIds);
        }

        return $q->get();
    }

    /**
     * Path PD pour fetchDepenseRows (classe 6).
     *
     * @param  array<int>|null  $operationIds
     * @return Collection<int, object>
     */
    private function fetchDepenseRowsPD(string $start, string $end, ?array $operationIds): Collection
    {
        return $this->fetchClasseRowsPD($start, $end, 6, $operationIds);
    }

    /**
     * Path PD pour fetchProduitsRows (classe 7).
     *
     * @param  array<int>|null  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsRowsPD(string $start, string $end, ?array $operationIds): Collection
    {
        return $this->fetchClasseRowsPD($start, $end, 7, $operationIds);
    }

    /**
     * Path PD pour fetchDepenseSeancesRows (classe 6, avec séance).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchDepenseSeancesRowsPD(string $start, string $end, array $operationIds): Collection
    {
        return $this->fetchClasseSeancesRowsPD($start, $end, 6, $operationIds);
    }

    /**
     * Path PD pour fetchProduitsSeancesRows (classe 7, avec séance).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsSeancesRowsPD(string $start, string $end, array $operationIds): Collection
    {
        return $this->fetchClasseSeancesRowsPD($start, $end, 7, $operationIds);
    }

    /**
     * Construit la requête PD pour les lignes d'une classe PCG avec ventilation par séance.
     *
     * La colonne `tl.seance` (entier, NULL → 0 = "Hors séance") est préservée telle quelle —
     * elle est agnostique du mode PD/legacy (colonne métier existant dans les 2 modes).
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchClasseSeancesRowsPD(string $start, string $end, int $classe, array $operationIds): Collection
    {
        $isSigne7 = $classe === 7;
        $montantExpr = $isSigne7
            ? DB::raw('SUM(tl.credit) - SUM(tl.debit) as montant')
            : DB::raw('SUM(tl.debit) - SUM(tl.credit) as montant');

        return DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as t', 'tl.transaction_id', '=', 't.id')
            ->leftJoin('categories as cat', 'c.categorie_id', '=', 'cat.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.date', [$start, $end])
            ->whereIn('tl.operation_id', $operationIds)
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('c.association_id', TenantContext::currentId()))
            ->select([
                DB::raw('COALESCE(cat.id, 0) as categorie_id'),
                DB::raw("COALESCE(cat.nom, '(sans catégorie)') as categorie_nom"),
                DB::raw('c.id as sous_categorie_id'),
                DB::raw('c.intitule as sous_categorie_nom'),
                DB::raw('COALESCE(tl.seance, 0) as seance'),
                $montantExpr,
            ])
            ->groupBy('c.id', 'c.intitule', 'cat.id', 'cat.nom', DB::raw('COALESCE(tl.seance, 0)'))
            ->get();
    }

    /**
     * Path PD pour fetchOperationRows (avec séances et/ou tiers optionnels).
     *
     * Note sur les affectations (transaction_ligne_affectations) :
     * En mode PD, les affectations portent le montant sous-découpé + operation_id métier.
     * La table affectations n'a PAS de colonne compte_id — le compte est sur la ligne parente.
     * Si une ligne PD porte des affectations, on utilise affectation.montant (répartition
     * des montants) mais le compte reste celui de la ligne parente.
     * Pour simplifier, on lit les lignes PD sans affectations + les lignes avec affectations
     * en récupérant le compte de la ligne parente. Le total est cohérent car
     * SUM(affectations.montant) = ligne.montant pour une ligne complètement affectée.
     *
     * @param  array<int>  $operationIds
     * @return array<string, array>
     */
    private function fetchOperationRowsPD(
        string $type,
        string $start,
        string $end,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
        bool $withOperation = false,
    ): array {
        $classe = $type === 'recette' ? 7 : 6;
        $isSigne7 = $classe === 7;

        $baseCols = [
            DB::raw('COALESCE(cat.id, 0) as categorie_id'),
            DB::raw("COALESCE(cat.nom, '(sans catégorie)') as categorie_nom"),
            DB::raw('c.id as sous_categorie_id'),
            DB::raw('c.intitule as sous_categorie_nom'),
        ];
        $baseGroup = ['c.id', 'c.intitule', 'cat.id', 'cat.nom'];

        if ($withTiers) {
            $baseCols = array_merge($baseCols, [
                DB::raw('COALESCE(tx.tiers_id, 0) as tiers_id'),
                DB::raw("COALESCE(trs.type, '') as tiers_type"),
                DB::raw("COALESCE(trs.nom, '') as tiers_nom"),
                DB::raw("COALESCE(trs.prenom, '') as tiers_prenom"),
                DB::raw("COALESCE(trs.entreprise, '') as tiers_entreprise"),
            ]);
            $baseGroup = array_merge($baseGroup, ['tx.tiers_id', 'trs.type', 'trs.nom', 'trs.prenom', 'trs.entreprise']);
        }

        // Q1 : lignes PD sans affectations
        $q1Cols = $baseCols;
        $q1Group = $baseGroup;
        if ($withSeance) {
            $q1Cols[] = DB::raw('COALESCE(tl.seance, 0) as seance');
            $q1Group[] = DB::raw('COALESCE(tl.seance, 0)');
        }
        if ($withOperation) {
            $q1Cols[] = 'tl.operation_id';
            $q1Group[] = 'tl.operation_id';
        }
        $montantQ1 = $isSigne7
            ? DB::raw('SUM(tl.credit) - SUM(tl.debit) as montant')
            : DB::raw('SUM(tl.debit) - SUM(tl.credit) as montant');
        $q1Cols[] = $montantQ1;

        $q1 = DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->leftJoin('categories as cat', 'c.categorie_id', '=', 'cat.id')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('tla.id')  // Lignes sans affectations
            ->whereIn('tl.operation_id', $operationIds)
            ->whereBetween('tx.date', [$start, $end])
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('c.association_id', TenantContext::currentId()))
            ->select($q1Cols)
            ->groupBy($q1Group);

        if ($withTiers) {
            $q1->leftJoin('tiers as trs', 'trs.id', '=', 'tx.tiers_id');
        }

        // Q2 : lignes PD avec affectations (montant = affectation.montant, operation = affectation.operation_id)
        // Le compte vient de la ligne parente — la table affectations est agnostique PD.
        $q2Cols = $baseCols;
        $q2Group = $baseGroup;
        if ($withSeance) {
            $q2Cols[] = DB::raw('COALESCE(tla2.seance, 0) as seance');
            $q2Group[] = DB::raw('COALESCE(tla2.seance, 0)');
        }
        if ($withOperation) {
            $q2Cols[] = 'tla2.operation_id';
            $q2Group[] = 'tla2.operation_id';
        }
        $q2Cols[] = DB::raw('SUM(tla2.montant) as montant');

        $q2 = DB::table('transaction_ligne_affectations as tla2')
            ->join('transaction_lignes as tl', 'tl.id', '=', 'tla2.transaction_ligne_id')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->leftJoin('categories as cat', 'c.categorie_id', '=', 'cat.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereIn('tla2.operation_id', $operationIds)
            ->whereBetween('tx.date', [$start, $end])
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('c.association_id', TenantContext::currentId()))
            ->select($q2Cols)
            ->groupBy($q2Group);

        if ($withTiers) {
            $q2->leftJoin('tiers as trs', 'trs.id', '=', 'tx.tiers_id');
        }

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
                if ($withOperation) {
                    $key .= '_op'.$row->operation_id;
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
                    if ($withOperation) {
                        $entry['operation_id'] = (int) $row->operation_id;
                    }
                    $map[$key] = $entry;
                }
            }
        }

        return $map;
    }

    // ── Prévisionnel ──────────────────────────────────────────────────────────

    /**
     * Injecte les catégories/SC qui existent dans les prévisions mais pas dans le réalisé,
     * avec des montants à zéro, pour qu'elles apparaissent dans l'affichage.
     *
     * @param  list<array<string, mixed>>  $realise
     * @param  list<array<string, mixed>>  $previsions
     * @return list<array<string, mixed>>
     */
    private function mergePrevisionsIntoHierarchy(array $realise, array $previsions): array
    {
        $byCatId = [];
        foreach ($realise as $cat) {
            $byCatId[(int) ($cat['categorie_id'] ?? $cat['id'] ?? 0)] = $cat;
        }

        foreach ($previsions as $prevCat) {
            $catId = (int) ($prevCat['categorie_id'] ?? $prevCat['id'] ?? 0);
            if (! isset($byCatId[$catId])) {
                $byCatId[$catId] = [
                    'categorie_id' => $catId,
                    'label' => $prevCat['label'],
                    'sous_categories' => [],
                    'montant' => 0.0,
                ];
            }

            $byScId = [];
            foreach ($byCatId[$catId]['sous_categories'] as $sc) {
                $byScId[(int) ($sc['sous_categorie_id'] ?? 0)] = $sc;
            }
            foreach ($prevCat['sous_categories'] as $prevSc) {
                $scId = (int) ($prevSc['sous_categorie_id'] ?? 0);
                if (! isset($byScId[$scId])) {
                    $byScId[$scId] = [
                        'sous_categorie_id' => $scId,
                        'label' => $prevSc['label'],
                        'montant' => 0.0,
                        'tiers' => [],
                    ];
                }

                if (! empty($prevSc['tiers'])) {
                    $existingTiersIds = [];
                    foreach ($byScId[$scId]['tiers'] ?? [] as $t) {
                        $existingTiersIds[(int) ($t['tiers_id'] ?? 0)] = true;
                    }
                    foreach ($prevSc['tiers'] as $prevT) {
                        $tId = (int) ($prevT['tiers_id'] ?? 0);
                        if (! isset($existingTiersIds[$tId])) {
                            $byScId[$scId]['tiers'][] = [
                                'tiers_id' => $tId,
                                'label' => $prevT['label'] ?? '—',
                                'type' => $prevT['type'] ?? null,
                                'seances' => [],
                                'montant' => 0.0,
                            ];
                        }
                    }
                }
            }
            $byCatId[$catId]['sous_categories'] = array_values($byScId);
        }

        return array_values($byCatId);
    }

    /**
     * Charges prévisionnelles depuis encadrement_previsions.
     *
     * @param  array<int>  $operationIds
     * @return list<array{label: string, id: int, sous_categories: list<array<string, mixed>>, seances?: array<int, float>, operations?: array<int, float>, total?: float, montant?: float}>
     */
    private function buildPrevisionsCharges(array $operationIds, bool $parSeances, bool $parTiers, bool $parOperations = false): array
    {
        $q = DB::table('encadrement_previsions as ep')
            ->join('sous_categories as sc', 'sc.id', '=', 'ep.sous_categorie_id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('seances as s', 's.id', '=', 'ep.seance_id')
            ->leftJoin('tiers as t', 't.id', '=', 'ep.tiers_id')
            ->whereIn('ep.operation_id', $operationIds)
            ->when(TenantContext::hasBooted(), fn ($x) => $x->where('ep.association_id', TenantContext::currentId()));

        $selects = ['c.id as categorie_id', 'c.nom as categorie_nom',
            'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
            DB::raw('SUM(ep.montant_prevu) as montant')];
        $groupBy = ['c.id', 'c.nom', 'sc.id', 'sc.nom'];

        if ($parSeances) {
            $selects[] = 's.numero as seance';
            $groupBy[] = 's.numero';
        }
        if ($parTiers) {
            $selects[] = 't.id as tiers_id';
            $selects[] = DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.prenom, t.nom)), ''), '—') as tiers_label");
            $groupBy[] = 't.id';
            $groupBy[] = 't.prenom';
            $groupBy[] = 't.nom';
        }
        if ($parOperations) {
            $selects[] = 'ep.operation_id';
            $groupBy[] = 'ep.operation_id';
        }

        $rows = $q->select($selects)->groupBy(...$groupBy)->get();

        return $this->hierarchiserPrevisions($rows, $parSeances, $parTiers, $parOperations, $operationIds);
    }

    /**
     * Produits prévisionnels depuis reglements.montant_prevu.
     *
     * @param  array<int>  $operationIds
     * @return list<array{label: string, id: int, sous_categories: list<array<string, mixed>>, seances?: array<int, float>, operations?: array<int, float>, total?: float, montant?: float}>
     */
    private function buildPrevisionsProduits(array $operationIds, bool $parSeances, bool $parTiers, bool $parOperations = false): array
    {
        $q = DB::table('reglements as r')
            ->join('participants as p', 'p.id', '=', 'r.participant_id')
            ->join('operations as op', 'op.id', '=', 'p.operation_id')
            ->join('type_operations as to_', 'to_.id', '=', 'op.type_operation_id')
            ->join('sous_categories as sc', 'sc.id', '=', 'to_.sous_categorie_id')
            ->join('categories as c', 'c.id', '=', 'sc.categorie_id')
            ->join('seances as s', 's.id', '=', 'r.seance_id')
            ->leftJoin('tiers as t', 't.id', '=', 'p.tiers_id')
            ->whereIn('p.operation_id', $operationIds)
            ->where('r.montant_prevu', '>', 0)
            ->when(TenantContext::hasBooted(), fn ($x) => $x->where('op.association_id', TenantContext::currentId()));

        $selects = ['c.id as categorie_id', 'c.nom as categorie_nom',
            'sc.id as sous_categorie_id', 'sc.nom as sous_categorie_nom',
            DB::raw('SUM(r.montant_prevu) as montant')];
        $groupBy = ['c.id', 'c.nom', 'sc.id', 'sc.nom'];

        if ($parSeances) {
            $selects[] = 's.numero as seance';
            $groupBy[] = 's.numero';
        }
        if ($parTiers) {
            $selects[] = 't.id as tiers_id';
            $selects[] = DB::raw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', t.prenom, t.nom)), ''), '—') as tiers_label");
            $groupBy[] = 't.id';
            $groupBy[] = 't.prenom';
            $groupBy[] = 't.nom';
        }
        if ($parOperations) {
            $selects[] = 'p.operation_id';
            $groupBy[] = 'p.operation_id';
        }

        $rows = $q->select($selects)->groupBy(...$groupBy)->get();

        return $this->hierarchiserPrevisions($rows, $parSeances, $parTiers, $parOperations, $operationIds);
    }

    /**
     * Hiérarchise des lignes prévisionnelles dans la même forme que buildHierarchyOperations.
     *
     * @param  array<int>  $operationIds  Nécessaire pour initialiser les clés quand parOperations=true
     * @return list<array<string, mixed>>
     */
    private function hierarchiserPrevisions(Collection $rows, bool $parSeances, bool $parTiers, bool $parOperations = false, array $operationIds = []): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $zeroOps = $parOperations ? array_fill_keys($operationIds, 0.0) : [];

        $tree = [];
        foreach ($rows as $row) {
            $catId = (int) $row->categorie_id;
            $scId = (int) $row->sous_categorie_id;

            if (! isset($tree[$catId])) {
                $tree[$catId] = [
                    'categorie_id' => $catId,
                    'label' => $row->categorie_nom,
                    'sous_categories' => [],
                    'seances' => [],
                    'montant' => 0.0,
                ];
                if ($parOperations) {
                    $tree[$catId]['operations'] = $zeroOps;
                }
            }
            if (! isset($tree[$catId]['sous_categories'][$scId])) {
                $tree[$catId]['sous_categories'][$scId] = [
                    'sous_categorie_id' => $scId,
                    'label' => $row->sous_categorie_nom,
                    'seances' => [],
                    'montant' => 0.0,
                    'tiers' => [],
                ];
                if ($parOperations) {
                    $tree[$catId]['sous_categories'][$scId]['operations'] = $zeroOps;
                }
            }

            $montant = (float) $row->montant;

            if ($parSeances) {
                $seanceNum = (int) ($row->seance ?? 0);
                $tree[$catId]['seances'][$seanceNum] = ($tree[$catId]['seances'][$seanceNum] ?? 0) + $montant;
                $tree[$catId]['sous_categories'][$scId]['seances'][$seanceNum] = ($tree[$catId]['sous_categories'][$scId]['seances'][$seanceNum] ?? 0) + $montant;
            }
            $tree[$catId]['montant'] += $montant;
            $tree[$catId]['sous_categories'][$scId]['montant'] += $montant;

            if ($parOperations) {
                $opId = (int) ($row->operation_id ?? 0);
                $tree[$catId]['operations'][$opId] = ($tree[$catId]['operations'][$opId] ?? 0.0) + $montant;
                $tree[$catId]['sous_categories'][$scId]['operations'][$opId] = ($tree[$catId]['sous_categories'][$scId]['operations'][$opId] ?? 0.0) + $montant;
            }

            if ($parTiers) {
                $tId = (int) ($row->tiers_id ?? 0);
                $tLabel = $row->tiers_label ?? '—';
                if (! isset($tree[$catId]['sous_categories'][$scId]['tiers'][$tId])) {
                    $tree[$catId]['sous_categories'][$scId]['tiers'][$tId] = [
                        'tiers_id' => $tId,
                        'label' => $tLabel,
                        'type' => null,
                        'seances' => [],
                        'montant' => 0.0,
                    ];
                }
                if ($parSeances) {
                    $seanceNum = (int) ($row->seance ?? 0);
                    $tree[$catId]['sous_categories'][$scId]['tiers'][$tId]['seances'][$seanceNum] = ($tree[$catId]['sous_categories'][$scId]['tiers'][$tId]['seances'][$seanceNum] ?? 0) + $montant;
                }
                $tree[$catId]['sous_categories'][$scId]['tiers'][$tId]['montant'] += $montant;
            }
        }

        foreach ($tree as &$cat) {
            $cat['sous_categories'] = array_values(array_map(function (array $sc): array {
                $sc['tiers'] = array_values($sc['tiers']);

                return $sc;
            }, $cat['sous_categories']));
        }

        return array_values($tree);
    }

    /**
     * Calcule les montants projetés dans une ProjectionMatrix :
     * réel > 0 → réel, sinon prévu, au grain (sc, tiers, séance, opération).
     *
     * @param  array<int>  $operationIds
     * @return array{charges: ProjectionMatrix, produits: ProjectionMatrix}
     */
    private function computeProjections(string $start, string $end, array $operationIds): array
    {
        $result = [];

        foreach (['depense', 'produits'] as $type) {
            $matrix = new ProjectionMatrix;
            $dbType = $type === 'produits' ? 'recette' : $type;

            // Réel au grain (sc, tiers, séance, op)
            $reelMap = $this->fetchOperationRows(
                $dbType, $start, $end, $operationIds, true, true, true,
            );

            $reelGrid = []; // [sc][tiers][seance][op] = montant
            $scToCat = [];
            foreach ($reelMap as $row) {
                $scId = (int) $row['sous_categorie_id'];
                $tiersId = (int) $row['tiers_id'];
                $seance = (int) $row['seance'];
                $opId = (int) $row['operation_id'];
                $reelGrid[$scId][$tiersId][$seance][$opId] = (float) $row['montant'];
                if (! isset($scToCat[$scId])) {
                    $scToCat[$scId] = (int) $row['categorie_id'];
                }
            }

            // Prévu au grain (sc, tiers, séance, op)
            $prevGrid = $type === 'depense'
                ? $this->fetchFlatPrevisionsCharges($operationIds)
                : $this->fetchFlatPrevisionsProduits($operationIds);

            // Collect scToCat from prévisions too
            foreach ($prevGrid as $scId => $tiersList) {
                if (! isset($scToCat[$scId])) {
                    $scToCat[$scId] = $this->lookupCategoryForSc($scId);
                }
            }

            // Register sc→cat mappings
            foreach ($scToCat as $scId => $catId) {
                $matrix->setScCategory($scId, $catId);
            }

            // Enumerate all (sc, tiers, séance, op) cells from both grids
            $allScIds = array_unique(array_merge(
                array_keys($reelGrid),
                array_keys($prevGrid),
            ));

            foreach ($allScIds as $scId) {
                $allTiersIds = array_unique(array_merge(
                    array_keys($reelGrid[$scId] ?? []),
                    array_keys($prevGrid[$scId] ?? []),
                ));

                foreach ($allTiersIds as $tiersId) {
                    $allSeances = array_unique(array_merge(
                        array_keys($reelGrid[$scId][$tiersId] ?? []),
                        array_keys($prevGrid[$scId][$tiersId] ?? []),
                    ));

                    foreach ($allSeances as $seance) {
                        $allOps = array_unique(array_merge(
                            array_keys($reelGrid[$scId][$tiersId][$seance] ?? []),
                            array_keys($prevGrid[$scId][$tiersId][$seance] ?? []),
                        ));

                        foreach ($allOps as $opId) {
                            $reel = (float) ($reelGrid[$scId][$tiersId][$seance][$opId] ?? 0);
                            $prevu = (float) ($prevGrid[$scId][$tiersId][$seance][$opId] ?? 0);
                            $proj = $reel > 0 ? $reel : $prevu;
                            if ($proj != 0.0) {
                                $matrix->set((int) $scId, (int) $tiersId, (int) $seance, (int) $opId, $proj);
                            }
                        }
                    }
                }
            }

            $key = $type === 'depense' ? 'charges' : 'produits';
            $result[$key] = $matrix;
        }

        return $result;
    }

    /**
     * Prévisions charges (encadrement_previsions) au grain plat : [sc][tiers][séance][op] = montant.
     *
     * @param  array<int>  $operationIds
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function fetchFlatPrevisionsCharges(array $operationIds): array
    {
        $rows = DB::table('encadrement_previsions as ep')
            ->join('seances as s', 's.id', '=', 'ep.seance_id')
            ->whereIn('ep.operation_id', $operationIds)
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('ep.association_id', TenantContext::currentId()))
            ->select([
                'ep.sous_categorie_id',
                DB::raw('COALESCE(ep.tiers_id, 0) as tiers_id'),
                's.numero as seance',
                'ep.operation_id',
                DB::raw('SUM(ep.montant_prevu) as montant'),
            ])
            ->groupBy('ep.sous_categorie_id', 'ep.tiers_id', 's.numero', 'ep.operation_id')
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row->sous_categorie_id][(int) $row->tiers_id][(int) $row->seance][(int) $row->operation_id]
                = (float) $row->montant;
        }

        return $grid;
    }

    /**
     * Prévisions produits (reglements.montant_prevu) au grain plat : [sc][tiers][séance][op] = montant.
     *
     * @param  array<int>  $operationIds
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function fetchFlatPrevisionsProduits(array $operationIds): array
    {
        $rows = DB::table('reglements as r')
            ->join('participants as p', 'p.id', '=', 'r.participant_id')
            ->join('operations as op', 'op.id', '=', 'p.operation_id')
            ->join('type_operations as to_', 'to_.id', '=', 'op.type_operation_id')
            ->join('sous_categories as sc', 'sc.id', '=', 'to_.sous_categorie_id')
            ->join('seances as s', 's.id', '=', 'r.seance_id')
            ->whereIn('p.operation_id', $operationIds)
            ->where('r.montant_prevu', '>', 0)
            ->when(TenantContext::hasBooted(), fn ($q) => $q->where('op.association_id', TenantContext::currentId()))
            ->select([
                'sc.id as sous_categorie_id',
                DB::raw('COALESCE(p.tiers_id, 0) as tiers_id'),
                's.numero as seance',
                'p.operation_id',
                DB::raw('SUM(r.montant_prevu) as montant'),
            ])
            ->groupBy('sc.id', 'p.tiers_id', 's.numero', 'p.operation_id')
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row->sous_categorie_id][(int) $row->tiers_id][(int) $row->seance][(int) $row->operation_id]
                = (float) $row->montant;
        }

        return $grid;
    }

    /**
     * Lookup catégorie pour une sous-catégorie (fallback pour SC prévision-only).
     */
    private function lookupCategoryForSc(int $scId): int
    {
        return (int) DB::table('sous_categories')
            ->where('id', $scId)
            ->value('categorie_id');
    }
}
