<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Famille;
use App\Models\Operation;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CompteResultatBuilder
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Compte de résultat complet : hiérarchie famille/compte avec N-1 et budget.
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
     * Totaux charges/produits d'une période, lus sur le grand livre (classes 6
     * et 7) — la définition même du résultat.
     *
     * Destiné aux indicateurs de synthèse (tableau de bord, assistant de
     * clôture) qui sommaient jusqu'ici `transactions.montant_total` filtré sur
     * le journal. Ce filtre ne sait pas distinguer un achat de fournitures d'un
     * achat de véhicule — les deux portent le journal `achat` — et comptait
     * donc une acquisition d'immobilisation (classe 2, actif au bilan) comme
     * une charge, tout en ignorant la dotation aux amortissements qui en est
     * la vraie charge, son journal étant `od`.
     *
     * La période est fournie par l'appelant, et non recalculée ici : le mois de
     * début d'exercice est un réglage du tenant (ExerciceService::dateRange()),
     * alors que exerciceDates() de cette classe le fige au 1er septembre.
     *
     * @return array{charges: float, produits: float}
     */
    public function totauxResultat(string $start, string $end): array
    {
        return [
            'charges' => round((float) $this->fetchDepenseRows($start, $end)->sum('montant'), 2),
            'produits' => round((float) $this->fetchClasseRowsPD($start, $end, 7)->sum('montant'), 2),
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
                    ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'association_id'))
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
                ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'association_id'))
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
            $prevC = $this->buildPrevisions('depense', $operationIds, $parSeances, $parTiers, $parOperations, $allSeances, $start, $end);
            $prevP = $this->buildPrevisions('recette', $operationIds, $parSeances, $parTiers, $parOperations, $allSeances, $start, $end);
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
     * Rapport par séances : hiérarchie famille/compte avec une colonne par séance.
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

    /**
     * Bornes de l'exercice, dérivées du paramétrage du tenant.
     *
     * Ce calcul était local et figé sur septembre-août, alors que le mois de
     * début appartient à l'association (`exercice_mois_debut`). Une
     * association en exercice civil ou décalé — mars-février dans l'hémisphère
     * sud — voyait donc ses montants pris sur la mauvaise période, en silence.
     *
     * ExerciceService est la seule source : il gère le décalage d'année, le
     * cas calendaire où l'exercice tient dans une seule année civile, et les
     * libellés correspondants.
     *
     * @return array{string, string}
     */
    private function exerciceDates(int $exercice): array
    {
        $range = $this->exerciceService->dateRange($exercice);

        return [$range['start']->toDateString(), $range['end']->toDateString()];
    }

    private function scopeToCurrentTenant(Builder $query, string $associationColumn): void
    {
        if (! TenantContext::hasBooted()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($associationColumn, TenantContext::currentId());
    }

    private function joinFamille(Builder $query): void
    {
        $query
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(cpt.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'cpt.association_id');
            });
    }

    /**
     * Agrégation des dépenses par (famille, compte) — lecture compte-first classe 6.
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre
     * @return Collection<int, object>
     */
    private function fetchDepenseRows(string $start, string $end, ?array $operationIds = null): Collection
    {
        return $this->fetchClasseRowsPD($start, $end, 6, $operationIds);
    }

    /**
     * Agrégation des produits par (famille, compte) — lecture compte-first classe 7.
     *
     * @param  array<int>|null  $operationIds  null = pas de filtre
     * @return Collection<int, object>
     */
    private function fetchProduitsRows(string $start, string $end, int $exercice, ?array $operationIds = null): Collection
    {
        return $this->fetchClasseRowsPD($start, $end, 7, $operationIds);
    }

    /**
     * Agrégation des dépenses par (famille, compte, séance) — lecture compte-first classe 6.
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchDepenseSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        return $this->fetchClasseSeancesRowsPD($start, $end, 6, $operationIds);
    }

    /**
     * Agrégation des produits par (famille, compte, séance) — lecture compte-first classe 7.
     *
     * @param  array<int>  $operationIds
     * @return Collection<int, object>
     */
    private function fetchProduitsSeancesRows(string $start, string $end, array $operationIds): Collection
    {
        return $this->fetchClasseSeancesRowsPD($start, $end, 7, $operationIds);
    }

    /**
     * Exécute la lecture compte-first et accumule dans une map plate à clé composite.
     *
     * @param  array<int>  $operationIds
     * @return array<string, array>
     */
    private function fetchOperationRows(
        string $type,
        ?string $start,
        ?string $end,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
        bool $withOperation = false,
    ): array {
        return $this->fetchOperationRowsPD($type, $start, $end, $operationIds, $withSeance, $withTiers, $withOperation);
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
            $catId = $entry['famille_id'];
            $scId = $entry['compte_id'];
            $montant = (float) $entry['montant'];

            // ── Initialisation famille ───────────────────────────────────────
            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'famille_id' => $catId,
                    'famille_nom' => $entry['famille_nom'],
                    'montant' => 0.0,
                    'comptes_map' => [],
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

            // ── Initialisation compte ────────────────────────────────────────
            if (! isset($categories[$catId]['comptes_map'][$scId])) {
                $scEntry = [
                    'compte_id' => $scId,
                    'compte_nom' => $entry['compte_nom'],
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
                $categories[$catId]['comptes_map'][$scId] = $scEntry;
            }

            // ── Init & accumulate tiers ──────────────────────────────────────
            if ($withTiers) {
                $tiersId = $entry['tiers_id'];
                if (! isset($categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId])) {
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
                    $categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId] = $tEntry;
                }

                $categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['montant'] += $montant;
                if ($withSeance) {
                    $categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['seances'][$entry['seance']] += $montant;
                }
                if ($withOperation) {
                    $opId = $entry['operation_id'];
                    $categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['operations'][$opId]
                        = ($categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['operations'][$opId] ?? 0.0) + $montant;
                }
                if ($withSeance && $withOperation) {
                    $seance = $entry['seance'];
                    $opId = $entry['operation_id'];
                    $categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['seance_operations'][$seance][$opId]
                        = ($categories[$catId]['comptes_map'][$scId]['tiers_map'][$tiersId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
                }
            }

            // ── Accumulate SC + cat ──────────────────────────────────────────
            $categories[$catId]['comptes_map'][$scId]['montant'] += $montant;
            $categories[$catId]['montant'] += $montant;

            if ($withSeance) {
                $seance = $entry['seance'];
                $categories[$catId]['comptes_map'][$scId]['seances'][$seance] += $montant;
                $categories[$catId]['seances'][$seance] += $montant;
            }
            if ($withOperation) {
                $opId = $entry['operation_id'];
                $categories[$catId]['comptes_map'][$scId]['operations'][$opId]
                    = ($categories[$catId]['comptes_map'][$scId]['operations'][$opId] ?? 0.0) + $montant;
                $categories[$catId]['operations'][$opId]
                    = ($categories[$catId]['operations'][$opId] ?? 0.0) + $montant;
            }
            if ($withSeance && $withOperation) {
                $seance = $entry['seance'];
                $opId = $entry['operation_id'];
                $categories[$catId]['comptes_map'][$scId]['seance_operations'][$seance][$opId]
                    = ($categories[$catId]['comptes_map'][$scId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
                $categories[$catId]['seance_operations'][$seance][$opId]
                    = ($categories[$catId]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
            }

            // ── Accumulation par exercice ────────────────────────────────────
            $annee = (int) ($entry['exercice'] ?? 0);

            $this->accumulerExercice(
                $categories[$catId], $annee, $montant, $entry,
                $withSeance, $withOperation, $allSeances, $zeroOps,
            );
            $this->accumulerExercice(
                $categories[$catId]['comptes_map'][$scId], $annee, $montant, $entry,
                $withSeance, $withOperation, $allSeances, $zeroOps,
            );

            if ($withTiers) {
                $tiersId = $entry['tiers_id'];
                $exNode = &$categories[$catId]['comptes_map'][$scId]['exercices_map'][$annee];
                if (! isset($exNode['tiers_map'][$tiersId])) {
                    $exNode['tiers_map'][$tiersId] = [
                        'tiers_id' => $tiersId,
                        'label' => $this->formatTiersLabel($entry),
                        'type' => $tiersId === 0 ? null : $entry['tiers_type'],
                        'montant' => 0.0,
                    ];
                    if ($withSeance) {
                        $exNode['tiers_map'][$tiersId]['seances'] = array_fill_keys($allSeances, 0.0);
                    }
                    if ($withOperation) {
                        $exNode['tiers_map'][$tiersId]['operations'] = $zeroOps;
                    }
                    if ($withSeance && $withOperation) {
                        $exNode['tiers_map'][$tiersId]['seance_operations'] = [];
                    }
                }

                $exNode['tiers_map'][$tiersId]['montant'] += $montant;
                if ($withSeance) {
                    $exNode['tiers_map'][$tiersId]['seances'][$entry['seance']]
                        = ($exNode['tiers_map'][$tiersId]['seances'][$entry['seance']] ?? 0.0) + $montant;
                }
                if ($withOperation) {
                    $opId = $entry['operation_id'];
                    $exNode['tiers_map'][$tiersId]['operations'][$opId]
                        = ($exNode['tiers_map'][$tiersId]['operations'][$opId] ?? 0.0) + $montant;
                }
                if ($withSeance && $withOperation) {
                    $exNode['tiers_map'][$tiersId]['seance_operations'][$entry['seance']][$entry['operation_id']]
                        = ($exNode['tiers_map'][$tiersId]['seance_operations'][$entry['seance']][$entry['operation_id']] ?? 0.0) + $montant;
                }
                unset($exNode);
            }
        }

        // ── Flatten & sort ───────────────────────────────────────────────────
        $result = [];
        foreach ($categories as $cat) {
            $scs = [];
            foreach ($cat['comptes_map'] as $sc) {
                if ($withTiers) {
                    $tiers = array_values($sc['tiers_map']);
                    usort($tiers, fn ($a, $b) => strcmp($a['label'], $b['label']));
                    $sc['tiers'] = $tiers;
                    unset($sc['tiers_map']);
                }

                $exercicesSc = $sc['exercices_map'] ?? [];
                foreach ($exercicesSc as $annee => $ex) {
                    if (! isset($ex['tiers_map'])) {
                        continue;
                    }
                    $exTiers = array_values($ex['tiers_map']);
                    usort($exTiers, fn ($a, $b) => strcmp($a['label'], $b['label']));
                    $exercicesSc[$annee]['tiers'] = $exTiers;
                    unset($exercicesSc[$annee]['tiers_map']);
                }
                [$sc['exercices'], $sc['montant_exercices']] = $this->flattenExercices($exercicesSc);
                unset($sc['exercices_map']);

                $scs[] = $sc;
            }
            usort($scs, fn ($a, $b) => strcmp($a['compte_nom'], $b['compte_nom']));
            $cat['comptes'] = $scs;
            unset($cat['comptes_map']);

            [$cat['exercices'], $cat['montant_exercices']] = $this->flattenExercices($cat['exercices_map'] ?? []);
            unset($cat['exercices_map']);

            $result[] = $cat;
        }
        usort($result, fn ($a, $b) => strcmp($a['famille_nom'], $b['famille_nom']));

        return $result;
    }

    /**
     * Libellé d'un exercice.
     *
     * Seule exception à « tout passe par ExerciceService » : la clé 0 n'est pas
     * une année. Elle regroupe les prévisions dont la séance n'a pas de date,
     * et `label(0)` produirait « 0-1 ». Son intitulé est donc littéral.
     */
    private function labelExercice(int $annee): string
    {
        return $annee === 0
            ? 'Exercice non déterminé'
            : $this->exerciceService->label($annee);
    }

    /**
     * Accumule un montant dans la sous-entrée d'exercice d'un nœud de la
     * hiérarchie (famille ou compte).
     *
     * Les mêmes dimensions que le nœud parent sont maintenues à l'intérieur de
     * chaque exercice : sans cela, activer « Tous les exercices » ferait perdre
     * les colonnes de séances ou d'opérations. L'exercice est une dimension de
     * LIGNE, il ne remplace aucune colonne.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $entry
     * @param  list<int>  $allSeances
     * @param  array<int, float>|null  $zeroOps
     */
    private function accumulerExercice(
        array &$node,
        int $annee,
        float $montant,
        array $entry,
        bool $withSeance,
        bool $withOperation,
        array $allSeances,
        ?array $zeroOps,
    ): void {
        if (! isset($node['exercices_map'][$annee])) {
            $ex = [
                'annee' => $annee,
                'label' => $this->labelExercice($annee),
                'montant' => 0.0,
            ];
            if ($withSeance) {
                $ex['seances'] = array_fill_keys($allSeances, 0.0);
            }
            if ($withOperation) {
                $ex['operations'] = $zeroOps;
            }
            if ($withSeance && $withOperation) {
                $ex['seance_operations'] = [];
            }
            $node['exercices_map'][$annee] = $ex;
        }

        $node['exercices_map'][$annee]['montant'] += $montant;

        if ($withSeance) {
            $seance = (int) $entry['seance'];
            $node['exercices_map'][$annee]['seances'][$seance]
                = ($node['exercices_map'][$annee]['seances'][$seance] ?? 0.0) + $montant;
        }
        if ($withOperation) {
            $opId = (int) $entry['operation_id'];
            $node['exercices_map'][$annee]['operations'][$opId]
                = ($node['exercices_map'][$annee]['operations'][$opId] ?? 0.0) + $montant;
        }
        if ($withSeance && $withOperation) {
            $seance = (int) $entry['seance'];
            $opId = (int) $entry['operation_id'];
            $node['exercices_map'][$annee]['seance_operations'][$seance][$opId]
                = ($node['exercices_map'][$annee]['seance_operations'][$seance][$opId] ?? 0.0) + $montant;
        }
    }

    /**
     * Transforme `exercices_map` en liste triée du plus récent au plus ancien,
     * « Exercice non déterminé » (clé 0) toujours en dernier.
     *
     * Retourne aussi la somme des montants retenus : elle devient
     * `montant_exercices`, le seul total que la vue doit afficher en portée
     * « tous les exercices ». Faire recalculer cette somme par Blade rouvrirait
     * la porte à un total qui ne couvre pas exactement les lignes affichées.
     *
     * @param  array<int, array<string, mixed>>  $map
     * @return array{list<array<string, mixed>>, float}
     */
    private function flattenExercices(array $map): array
    {
        $entries = array_values($map);
        usort($entries, function (array $a, array $b): int {
            if ((int) $a['annee'] === 0) {
                return 1;
            }
            if ((int) $b['annee'] === 0) {
                return -1;
            }

            return (int) $b['annee'] <=> (int) $a['annee'];
        });

        $total = 0.0;
        foreach ($entries as $entry) {
            $total += (float) $entry['montant'];
        }

        return [$entries, $total];
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
     * @return array<int, float> [compte_id => montant_prevu]
     */
    private function fetchBudgetMap(int $exercice): array
    {
        return DB::table('budget_lines')
            ->whereNotNull('compte_id')
            ->where('exercice', $exercice)
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'association_id'))
            ->select('compte_id', DB::raw('SUM(montant_prevu) as budget'))
            ->groupBy('compte_id')
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
        // Map intermédiaire keyed by compte_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flatN as $row) {
            $scId = (int) $row->compte_id;
            $map[$scId] = [
                'famille_id' => (int) $row->famille_id,
                'famille_nom' => $row->famille_nom,
                'compte_nom' => $row->compte_nom,
                'montant_n' => (float) $row->montant,
                'montant_n1' => null,
                'budget' => $budgetMap[$scId] ?? null,
            ];
        }

        foreach ($flatN1 as $row) {
            $scId = (int) $row->compte_id;
            if (isset($map[$scId])) {
                $map[$scId]['montant_n1'] = (float) $row->montant;
            } else {
                // Compte présent en N-1 mais pas en N
                $map[$scId] = [
                    'famille_id' => (int) $row->famille_id,
                    'famille_nom' => $row->famille_nom,
                    'compte_nom' => $row->compte_nom,
                    'montant_n' => 0.0,
                    'montant_n1' => (float) $row->montant,
                    'budget' => $budgetMap[$scId] ?? null,
                ];
            }
        }

        return $this->groupByFamille($map, true);
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
        // Map keyed by compte_id
        /** @var array<int, array> */
        $map = [];

        foreach ($flat as $row) {
            $scId = (int) $row->compte_id;
            $seance = (int) $row->seance;

            if (! isset($map[$scId])) {
                $map[$scId] = [
                    'famille_id' => (int) $row->famille_id,
                    'famille_nom' => $row->famille_nom,
                    'compte_nom' => $row->compte_nom,
                    'seances' => [],
                    'total' => 0.0,
                ];
            }
            $map[$scId]['seances'][$seance] = ($map[$scId]['seances'][$seance] ?? 0.0) + (float) $row->montant;
            $map[$scId]['total'] += (float) $row->montant;
        }

        // Regroupement par famille
        /** @var array<int, array> */
        $categories = [];
        foreach ($map as $scId => $sc) {
            $catId = $sc['famille_id'];
            if (! isset($categories[$catId])) {
                $categories[$catId] = [
                    'famille_id' => $catId,
                    'famille_nom' => $sc['famille_nom'],
                    'seances' => array_fill_keys($allSeances, 0.0),
                    'total' => 0.0,
                    'comptes' => [],
                ];
            }

            // Complète les séances manquantes du compte avec 0.0
            $scSeances = [];
            foreach ($allSeances as $s) {
                $scSeances[$s] = $sc['seances'][$s] ?? 0.0;
            }

            foreach ($allSeances as $s) {
                $categories[$catId]['seances'][$s] += $scSeances[$s];
            }
            $categories[$catId]['total'] += $sc['total'];

            $categories[$catId]['comptes'][] = [
                'compte_id' => $scId,
                'compte_nom' => $sc['compte_nom'],
                'seances' => $scSeances,
                'total' => $sc['total'],
            ];
        }

        usort($categories, fn ($a, $b) => strcmp($a['famille_nom'], $b['famille_nom']));
        foreach ($categories as &$cat) {
            usort($cat['comptes'], fn ($a, $b) => strcmp($a['compte_nom'], $b['compte_nom']));
        }

        return array_values($categories);
    }

    /**
     * Regroupe la map plate en hiérarchie famille → comptes.
     *
     * @param  array<int, array>  $map  Keyed by compte_id
     * @param  bool  $withN1Budget  Inclure montant_n1 et budget dans le retour
     * @return list<array>
     */
    private function groupByFamille(array $map, bool $withN1Budget): array
    {
        /** @var array<int, array> */
        $categories = [];

        foreach ($map as $scId => $sc) {
            $catId = $sc['famille_id'];

            if (! isset($categories[$catId])) {
                $cat = [
                    'famille_id' => $catId,
                    'famille_nom' => $sc['famille_nom'],
                    'comptes' => [],
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
                $categories[$catId]['comptes'][] = [
                    'compte_id' => $scId,
                    'compte_nom' => $sc['compte_nom'],
                    'montant_n' => $sc['montant_n'],
                    'montant_n1' => $sc['montant_n1'],
                    'budget' => $sc['budget'],
                ];
            } else {
                $categories[$catId]['montant'] += $sc['montant'];
                $categories[$catId]['comptes'][] = [
                    'compte_id' => $scId,
                    'compte_nom' => $sc['compte_nom'],
                    'montant' => $sc['montant'],
                ];
            }
        }

        usort($categories, fn ($a, $b) => strcmp($a['famille_nom'], $b['famille_nom']));
        foreach ($categories as &$cat) {
            usort($cat['comptes'], fn ($a, $b) => strcmp($a['compte_nom'], $b['compte_nom']));
        }

        return array_values($categories);
    }

    // ── Lecture compte-first (débit/crédit par classe PCG) ───────────────────

    /**
     * Construit la requête DB pour les lignes PD d'une classe PCG donnée (6 ou 7).
     *
     * Lecture : transaction_lignes JOIN comptes (classe = $classe) + JOIN familles via le
     * préfixe à 2 chiffres de comptes.numero_pcg (DC-4 : la famille remplace la catégorie
     * comme regroupement de premier niveau, cf. App\Models\Famille).
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
     * @return Collection<int, object> Colonnes : famille_id (id famille), famille_nom (libellé famille), compte_id (id compte), compte_nom, montant
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
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.date', [$start, $end])
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'c.association_id'))
            ->select([
                DB::raw('COALESCE(f.id, 0) as famille_id'),
                DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
                // En mode PD, compte_id = compte_id (mapping transparent pour les builders hiérarchie)
                DB::raw('c.id as compte_id'),
                DB::raw('c.intitule as compte_nom'),
                $montantExpr,
            ])
            ->groupBy('c.id', 'c.intitule', 'f.id', 'f.code', 'f.nom');

        if ($operationIds !== null) {
            $q->whereIn('tl.operation_id', $operationIds);
        }

        return $q->get();
    }

    /**
     * Construit la requête pour les lignes d'une classe PCG avec ventilation par séance.
     *
     * La colonne `tl.seance` (entier, NULL → 0 = "Hors séance") est préservée telle quelle.
     *
     * Même motif Q1/Q2 que {@see fetchOperationRowsPD} : les lignes portant des
     * affectations (transaction_ligne_affectations) sont exclues de Q1 et lues via Q2
     * au grain affectation (montant, operation_id et seance de l'affectation ; le
     * compte reste celui de la ligne parente). Le consommateur (buildHierarchySeances)
     * accumule les lignes des deux requêtes sur la même clé (compte, séance).
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

        // Q1 : lignes sans affectations (seance/operation directs sur la ligne)
        $rows1 = DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as t', 'tl.transaction_id', '=', 't.id')
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereNull('tla.id')
            ->whereBetween('t.date', [$start, $end])
            ->whereIn('tl.operation_id', $operationIds)
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'c.association_id'))
            ->select([
                DB::raw('COALESCE(f.id, 0) as famille_id'),
                DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
                DB::raw('c.id as compte_id'),
                DB::raw('c.intitule as compte_nom'),
                DB::raw('COALESCE(tl.seance, 0) as seance'),
                $montantExpr,
            ])
            ->groupBy('c.id', 'c.intitule', 'f.id', 'f.code', 'f.nom', DB::raw('COALESCE(tl.seance, 0)'))
            ->get();

        // Q2 : affectations (montant/seance/operation de l'affectation, compte de la ligne parente)
        $rows2 = DB::table('transaction_ligne_affectations as tla2')
            ->join('transaction_lignes as tl', 'tl.id', '=', 'tla2.transaction_ligne_id')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as t', 'tl.transaction_id', '=', 't.id')
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('t.deleted_at')
            ->whereBetween('t.date', [$start, $end])
            ->whereIn('tla2.operation_id', $operationIds)
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'c.association_id'))
            ->select([
                DB::raw('COALESCE(f.id, 0) as famille_id'),
                DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
                DB::raw('c.id as compte_id'),
                DB::raw('c.intitule as compte_nom'),
                DB::raw('COALESCE(tla2.seance, 0) as seance'),
                DB::raw('SUM(tla2.montant) as montant'),
            ])
            ->groupBy('c.id', 'c.intitule', 'f.id', 'f.code', 'f.nom', DB::raw('COALESCE(tla2.seance, 0)'))
            ->get();

        return $rows1->concat($rows2);
    }

    /**
     * Implémentation de fetchOperationRows (avec séances et/ou tiers optionnels).
     *
     * Note sur les affectations (transaction_ligne_affectations) :
     * Les affectations portent le montant sous-découpé + operation_id métier.
     * La table affectations n'a PAS de colonne compte_id — le compte est sur la ligne parente.
     * Si une ligne porte des affectations, on utilise affectation.montant (répartition
     * des montants) mais le compte reste celui de la ligne parente.
     * Pour simplifier, on lit les lignes sans affectations + les lignes avec affectations
     * en récupérant le compte de la ligne parente. Le total est cohérent car
     * SUM(affectations.montant) = ligne.montant pour une ligne complètement affectée.
     *
     * @param  string|null  $start  null en portée « tous les exercices » — aucune borne de date
     * @param  string|null  $end  null en portée « tous les exercices » — aucune borne de date
     * @param  array<int>  $operationIds
     * @return array<string, array> clé : exercice_compte[_tiers][_seance][_op]
     */
    private function fetchOperationRowsPD(
        string $type,
        ?string $start,
        ?string $end,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
        bool $withOperation = false,
    ): array {
        $classe = $type === 'recette' ? 7 : 6;
        $isSigne7 = $classe === 7;

        $baseCols = [
            DB::raw('COALESCE(f.id, 0) as famille_id'),
            DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
            DB::raw('c.id as compte_id'),
            DB::raw('c.intitule as compte_nom'),
            // L'agrégation SQL descend au grain date ; l'année d'exercice est
            // ensuite calculée en PHP par ExerciceService::anneeForDate().
            // Aucune expression de date spécifique à un moteur n'est écrite ici
            // — SQLite (tests) et MySQL (prod) n'ont pas la même, et le
            // calendrier d'exercice appartient de toute façon au tenant.
            DB::raw('tx.date as tx_date'),
        ];
        $baseGroup = ['c.id', 'c.intitule', 'f.id', 'f.code', 'f.nom', 'tx.date'];

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
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('tla.id')  // Lignes sans affectations
            ->whereIn('tl.operation_id', $operationIds)
            // Portée « tous les exercices » : aucune borne. La date reste
            // sélectionnée et groupée dans les deux cas — c'est elle qui porte
            // l'exercice.
            ->when(
                $start !== null && $end !== null,
                fn (Builder $q) => $q->whereBetween('tx.date', [$start, $end]),
            )
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'c.association_id'))
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
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->where('c.classe', $classe)
            ->whereNotNull('tl.compte_id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereIn('tla2.operation_id', $operationIds)
            // Portée « tous les exercices » : aucune borne. La date reste
            // sélectionnée et groupée dans les deux cas — c'est elle qui porte
            // l'exercice.
            ->when(
                $start !== null && $end !== null,
                fn (Builder $q) => $q->whereBetween('tx.date', [$start, $end]),
            )
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'c.association_id'))
            ->select($q2Cols)
            ->groupBy($q2Group);

        if ($withTiers) {
            $q2->leftJoin('tiers as trs', 'trs.id', '=', 'tx.tiers_id');
        }

        $map = [];
        foreach ([$q1->get(), $q2->get()] as $rows) {
            foreach ($rows as $row) {
                $annee = $this->exerciceService->anneeForDate(
                    CarbonImmutable::parse((string) $row->tx_date)
                );

                $key = $annee.'_'.$row->compte_id;
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
                        'exercice' => $annee,
                        'famille_id' => (int) $row->famille_id,
                        'famille_nom' => $row->famille_nom,
                        'compte_id' => (int) $row->compte_id,
                        'compte_nom' => $row->compte_nom,
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
     * Injecte les familles/comptes qui existent dans les prévisions mais pas dans le réalisé,
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
            $byCatId[(int) ($cat['famille_id'] ?? $cat['id'] ?? 0)] = $cat;
        }

        foreach ($previsions as $prevCat) {
            $catId = (int) ($prevCat['famille_id'] ?? $prevCat['id'] ?? 0);
            if (! isset($byCatId[$catId])) {
                $byCatId[$catId] = [
                    'famille_id' => $catId,
                    'famille_nom' => $prevCat['famille_nom'],
                    'comptes' => [],
                    'montant' => 0.0,
                ];
            }

            $byScId = [];
            foreach ($byCatId[$catId]['comptes'] as $sc) {
                $byScId[(int) ($sc['compte_id'] ?? 0)] = $sc;
            }
            foreach ($prevCat['comptes'] as $prevSc) {
                $scId = (int) ($prevSc['compte_id'] ?? 0);
                if (! isset($byScId[$scId])) {
                    $byScId[$scId] = [
                        'compte_id' => $scId,
                        'compte_nom' => $prevSc['compte_nom'],
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
            $byCatId[$catId]['comptes'] = array_values($byScId);
        }

        return array_values($byCatId);
    }

    /**
     * Prévisions au format plat de fetchOperationRowsPD() — mêmes colonnes,
     * même clé de map — pour que buildUnifiedHierarchy() les hiérarchise à
     * l'identique du réalisé, au lieu de l'ancienne implémentation séparée
     * hiérarchiserPrevisions().
     *
     * Jointures calquées jointure pour jointure sur les anciennes
     * buildPrevisionsCharges()/buildPrevisionsProduits() ; seuls le filtre de
     * date et la forme de sortie changent.
     *
     * Filtre de date : en portée courante ($start/$end non nuls), on ne garde
     * que les prévisions dont la séance tombe dans l'exercice affiché, plus
     * celles dont la séance n'a pas de date (groupe « Exercice non
     * déterminé », clé 0 — jamais fondu dans l'exercice affiché). Le OR
     * DOIT rester dans sa propre closure : un OR de premier niveau se
     * détacherait de toute la conjonction qui le précède (opération
     * sélectionnée ET tenant courant) et ferait fuiter les prévisions
     * d'autres associations — c'est le piège que
     * CompteResultatBuilderPrevisionsPlatesTest.php attrape par mutation. En
     * portée « tous les exercices » ($start/$end nuls), aucun filtre.
     *
     * @param  array<int>  $operationIds
     * @param  string|null  $start  null en portée « tous les exercices »
     * @param  string|null  $end  null en portée « tous les exercices »
     * @return array<string, array> clé : exercice_compte[_tiers][_seance][_op]
     */
    private function fetchPrevisionsFlatEntries(
        string $type,
        array $operationIds,
        bool $withSeance,
        bool $withTiers,
        bool $withOperation,
        ?string $start,
        ?string $end,
    ): array {
        if ($type === 'depense') {
            $q = DB::table('encadrement_previsions as ep')
                ->join('comptes as cpt', 'cpt.id', '=', 'ep.compte_id')
                ->join('seances as s', 's.id', '=', 'ep.seance_id')
                ->leftJoin('tiers as trs', 'trs.id', '=', 'ep.tiers_id')
                ->whereIn('ep.operation_id', $operationIds)
                ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'ep.association_id'));
            $this->joinFamille($q);

            $tiersIdCol = 'ep.tiers_id';
            $operationIdCol = 'ep.operation_id';
            $montantExpr = DB::raw('SUM(ep.montant_prevu) as montant');
        } else {
            $q = DB::table('reglements as r')
                ->join('participants as p', 'p.id', '=', 'r.participant_id')
                ->join('operations as op', 'op.id', '=', 'p.operation_id')
                ->join('type_operations as to_', 'to_.id', '=', 'op.type_operation_id')
                ->join('comptes as cpt', 'cpt.id', '=', 'to_.compte_id')
                ->join('seances as s', 's.id', '=', 'r.seance_id')
                ->leftJoin('tiers as trs', 'trs.id', '=', 'p.tiers_id')
                ->whereIn('p.operation_id', $operationIds)
                ->where('r.montant_prevu', '>', 0)
                ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'op.association_id'));
            $this->joinFamille($q);

            $tiersIdCol = 'p.tiers_id';
            $operationIdCol = 'p.operation_id';
            $montantExpr = DB::raw('SUM(r.montant_prevu) as montant');
        }

        $q->when($start !== null && $end !== null, fn (Builder $query) => $query->where(
            fn (Builder $w) => $w->whereNull('s.date')->orWhereBetween('s.date', [$start, $end])
        ));

        $cols = [
            DB::raw('COALESCE(f.id, 0) as famille_id'),
            DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
            DB::raw('cpt.id as compte_id'),
            DB::raw('cpt.intitule as compte_nom'),
            DB::raw('s.date as seance_date'),
        ];
        $group = ['cpt.id', 'cpt.intitule', 'f.id', 'f.code', 'f.nom', 's.date'];

        if ($withSeance) {
            $cols[] = DB::raw('COALESCE(s.numero, 0) as seance');
            $group[] = DB::raw('COALESCE(s.numero, 0)');
        }
        if ($withTiers) {
            $cols[] = DB::raw("COALESCE({$tiersIdCol}, 0) as tiers_id");
            $cols[] = DB::raw("COALESCE(trs.type, '') as tiers_type");
            $cols[] = DB::raw("COALESCE(trs.nom, '') as tiers_nom");
            $cols[] = DB::raw("COALESCE(trs.prenom, '') as tiers_prenom");
            $cols[] = DB::raw("COALESCE(trs.entreprise, '') as tiers_entreprise");
            $group[] = $tiersIdCol;
            $group[] = 'trs.type';
            $group[] = 'trs.nom';
            $group[] = 'trs.prenom';
            $group[] = 'trs.entreprise';
        }
        if ($withOperation) {
            $cols[] = DB::raw("{$operationIdCol} as operation_id");
            $group[] = $operationIdCol;
        }
        $cols[] = $montantExpr;

        $rows = $q->select($cols)->groupBy($group)->get();

        $map = [];
        foreach ($rows as $row) {
            // Une séance sans date ne peut pas être rattachée à un exercice :
            // elle va dans le groupe 0, jamais dans l'exercice affiché.
            $annee = $row->seance_date === null
                ? 0
                : $this->exerciceService->anneeForDate(CarbonImmutable::parse((string) $row->seance_date));

            $key = $annee.'_'.$row->compte_id;
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
                    'exercice' => $annee,
                    'famille_id' => (int) $row->famille_id,
                    'famille_nom' => $row->famille_nom,
                    'compte_id' => (int) $row->compte_id,
                    'compte_nom' => $row->compte_nom,
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

        return $map;
    }

    /**
     * Prévisions (charges ou produits) hiérarchisées avec buildUnifiedHierarchy() —
     * la même méthode que le réalisé, plutôt que l'ancienne implémentation
     * dupliquée hiérarchiserPrevisions().
     *
     * @param  array<int>  $operationIds
     * @param  list<int>  $allSeances
     * @param  string|null  $start  null en portée « tous les exercices »
     * @param  string|null  $end  null en portée « tous les exercices »
     * @return list<array<string, mixed>>
     */
    private function buildPrevisions(
        string $type,
        array $operationIds,
        bool $parSeances,
        bool $parTiers,
        bool $parOperations,
        array $allSeances,
        ?string $start,
        ?string $end,
    ): array {
        $map = $this->fetchPrevisionsFlatEntries(
            $type, $operationIds, $parSeances, $parTiers, $parOperations, $start, $end,
        );

        return $this->buildUnifiedHierarchy(
            $map, $parSeances, $parTiers, $parOperations, $allSeances, $operationIds,
        );
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
                $scId = (int) $row['compte_id'];
                $tiersId = (int) $row['tiers_id'];
                $seance = (int) $row['seance'];
                $opId = (int) $row['operation_id'];
                $reelGrid[$scId][$tiersId][$seance][$opId] = (float) $row['montant'];
                if (! isset($scToCat[$scId])) {
                    $scToCat[$scId] = (int) $row['famille_id'];
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
     * Prévisions charges (encadrement_previsions) au grain plat : [compte_id][tiers][séance][op] = montant.
     *
     * Clé de grain alignée sur {@see fetchOperationRows} (DC-4 : compte_id, résolu depuis
     * compte_id via code_cerfa = numero_pcg) — indispensable pour que
     * {@see computeProjections} puisse fusionner réel et prévu sur la même clé.
     *
     * @param  array<int>  $operationIds
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function fetchFlatPrevisionsCharges(array $operationIds): array
    {
        $q = DB::table('encadrement_previsions as ep')
            ->join('comptes as cpt', 'cpt.id', '=', 'ep.compte_id')
            ->join('seances as s', 's.id', '=', 'ep.seance_id')
            ->whereIn('ep.operation_id', $operationIds)
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'ep.association_id'));
        $this->joinFamille($q);
        $rows = $q
            ->select([
                DB::raw('cpt.id as compte_id'),
                DB::raw('COALESCE(ep.tiers_id, 0) as tiers_id'),
                's.numero as seance',
                'ep.operation_id',
                DB::raw('SUM(ep.montant_prevu) as montant'),
            ])
            ->groupBy('cpt.id', 'ep.tiers_id', 's.numero', 'ep.operation_id')
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row->compte_id][(int) $row->tiers_id][(int) $row->seance][(int) $row->operation_id]
                = (float) $row->montant;
        }

        return $grid;
    }

    /**
     * Prévisions produits (reglements.montant_prevu) au grain plat : [compte_id][tiers][séance][op] = montant.
     *
     * Clé de grain alignée sur {@see fetchOperationRows} (DC-4), même raison que
     * {@see fetchFlatPrevisionsCharges}.
     *
     * @param  array<int>  $operationIds
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function fetchFlatPrevisionsProduits(array $operationIds): array
    {
        $q = DB::table('reglements as r')
            ->join('participants as p', 'p.id', '=', 'r.participant_id')
            ->join('operations as op', 'op.id', '=', 'p.operation_id')
            ->join('type_operations as to_', 'to_.id', '=', 'op.type_operation_id')
            ->join('comptes as cpt', 'cpt.id', '=', 'to_.compte_id')
            ->join('seances as s', 's.id', '=', 'r.seance_id')
            ->whereIn('p.operation_id', $operationIds)
            ->where('r.montant_prevu', '>', 0)
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'op.association_id'));
        $this->joinFamille($q);
        $rows = $q
            ->select([
                DB::raw('cpt.id as compte_id'),
                DB::raw('COALESCE(p.tiers_id, 0) as tiers_id'),
                's.numero as seance',
                'p.operation_id',
                DB::raw('SUM(r.montant_prevu) as montant'),
            ])
            ->groupBy('cpt.id', 'p.tiers_id', 's.numero', 'p.operation_id')
            ->get();

        $grid = [];
        foreach ($rows as $row) {
            $grid[(int) $row->compte_id][(int) $row->tiers_id][(int) $row->seance][(int) $row->operation_id]
                = (float) $row->montant;
        }

        return $grid;
    }

    /**
     * Lookup famille (compte_id retourné par fetchOperationRows/fetchFlatPrevisions*) pour un
     * compte sans ligne réalisée (fallback pour compte prévision-only).
     */
    private function lookupCategoryForSc(int $scId): int
    {
        return (int) DB::table('comptes')
            ->leftJoin('familles', function ($join): void {
                $join->on('familles.code', '=', DB::raw('SUBSTR(comptes.numero_pcg, 1, 2)'))
                    ->on('familles.association_id', '=', 'comptes.association_id');
            })
            ->tap(fn (Builder $query) => $this->scopeToCurrentTenant($query, 'comptes.association_id'))
            ->where('comptes.id', $scId)
            ->value('familles.id');
    }
}
