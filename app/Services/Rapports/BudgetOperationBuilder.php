<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Operation;
use App\Services\BudgetService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Budget ventilé, prévisionnel et réalisé d'une opération, à la maille compte.
 *
 * Construit pour le futur rapport « Budget par opérations » (Tasks 5 à 8) :
 * le budget d'une ligne y est toujours la ventilation (`budget_lines.operation_id`
 * renseigné), jamais l'enveloppe. L'existence d'une enveloppe globale est sans
 * objet à cette maille.
 *
 * ── Trois sources créent des lignes, à égalité ──────────────────────────────
 *
 * Une ventilation, un réalisé ou une prévision suffit à faire exister un compte
 * dans le tableau. C'est la leçon directe du lot 2a : une grandeur dont on
 * affiche le total doit pouvoir créer sa propre ligne, sinon le total ne se
 * retrouve pas dans le tableau qui le porte.
 *
 * ── null n'est pas 0.0 ──────────────────────────────────────────────────────
 *
 * `budget` et `prevision` valent `null` quand aucune ligne source ne couvre le
 * compte ; le consommateur doit alors afficher une case vide. Un `0.0` dirait
 * « aucune subvention attendue » en face de 1 200 € budgétés, ce qui est faux :
 * le prévisionnel ne couvre que les règlements des participants et les coûts
 * d'encadrement, jamais une subvention. Ne JAMAIS écrire `?? 0.0` sur ces deux
 * clés.
 *
 * `realise` est toujours un float : sa source ({@see BudgetService::realiseParCompteEtOperation()})
 * couvre tout le plan comptable, un zéro y signifie vraiment « rien n'a bougé ».
 *
 * ── Les trois grandeurs sont comparables telles quelles ─────────────────────
 *
 * `budget` et `prevision` sont des montants POSITIFS quelle que soit la classe :
 * ni `budget_lines.montant_prevu` ni les prévisions ne portent de signe, c'est la
 * classe du compte qui porte le sens. `realise` vient de `SensMontantPd`, qui rend
 * une charge positive au débit et un produit positif au crédit — donc positif lui
 * aussi dans le cas normal, et négatif seulement pour un contra-compte (709 au
 * débit, 609 au crédit), ce qui est exactement ce qu'on veut voir.
 *
 * Conséquence : sur une même ligne, les trois colonnes se comparent directement,
 * sans conversion. Ne JAMAIS introduire de `abs()` ni d'inversion de signe par
 * classe — ce projet a corrigé huit défauts de sens, tous nés d'un signe
 * appliqué deux fois.
 */
final class BudgetOperationBuilder
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly CompteResultatBuilder $compteResultatBuilder,
    ) {}

    /**
     * @param  list<int>  $operationIds
     * @return array<int, array<string, mixed>>
     */
    public function parOperations(int $exercice, array $operationIds): array
    {
        if ($operationIds === [] || ! TenantContext::hasBooted()) {
            return [];
        }

        $ventilations = $this->ventilations($exercice, $operationIds);
        $realise = $this->realiseParOperationEtCompte($exercice, $operationIds);
        $previsions = $this->compteResultatBuilder
            ->previsionsParOperationEtCompte($exercice, $operationIds);

        // Union des comptes cités par au moins une source, par opération.
        $comptesParOperation = [];
        foreach ([$ventilations, $realise, $previsions] as $source) {
            foreach ($source as $opId => $parCompte) {
                foreach (array_keys($parCompte) as $compteId) {
                    $comptesParOperation[(int) $opId][(int) $compteId] = true;
                }
            }
        }

        $tousComptes = [];
        foreach ($comptesParOperation as $parCompte) {
            foreach (array_keys($parCompte) as $compteId) {
                $tousComptes[$compteId] = true;
            }
        }

        $meta = $this->metaComptes(array_keys($tousComptes));
        $noms = Operation::whereIn('id', $operationIds)->pluck('nom', 'id')->all();

        $resultat = [];
        foreach ($operationIds as $opId) {
            $opId = (int) $opId;

            $sections = [6 => [], 7 => []];
            foreach (array_keys($comptesParOperation[$opId] ?? []) as $compteId) {
                if (! isset($meta[$compteId])) {
                    continue;  // compte d'un autre tenant : metaComptes l'a écarté
                }
                $m = $meta[$compteId];
                $budget = $ventilations[$opId][$compteId] ?? null;
                $realiseCompte = $realise[$opId][$compteId] ?? 0.0;

                $sections[$m['classe']][$m['famille_id']]['famille_id'] = $m['famille_id'];
                $sections[$m['classe']][$m['famille_id']]['famille_nom'] = $m['famille_nom'];
                $sections[$m['classe']][$m['famille_id']]['comptes'][] = [
                    'compte_id' => $compteId,
                    'compte_nom' => $m['compte_nom'],
                    'budget' => $budget,
                    'prevision' => $previsions[$opId][$compteId] ?? null,
                    'realise' => $realiseCompte,
                    // Le marqueur se rattache au RÉALISÉ, pas à la ligne : un
                    // compte qui n'a qu'une prévision n'a rien consommé, il n'y
                    // a rien à qualifier.
                    'hors_dotation' => $budget === null && $realiseCompte != 0.0,
                ];
            }

            $charges = $this->finaliserSection($sections[6]);
            $produits = $this->finaliserSection($sections[7]);

            $resultat[$opId] = [
                'operation_id' => $opId,
                'operation_nom' => (string) ($noms[$opId] ?? '—'),
                'charges' => $charges,
                'produits' => $produits,
                'totaux' => [
                    'charges' => $this->agreger($charges),
                    'produits' => $this->agreger($produits),
                ],
            ];
        }

        return $resultat;
    }

    /**
     * Ventilations budgétaires de l'exercice : operation_id => [compte_id => prévu].
     *
     * @param  list<int>  $operationIds
     * @return array<int, array<int, float>>
     */
    private function ventilations(int $exercice, array $operationIds): array
    {
        $rows = DB::table('budget_lines as bl')
            ->join('comptes as c', 'c.id', '=', 'bl.compte_id')
            ->whereNotNull('bl.operation_id')
            ->whereNotNull('bl.compte_id')
            ->whereIn('bl.operation_id', $operationIds)
            ->where('bl.exercice', $exercice)
            ->whereIn('c.classe', [6, 7])
            // Deux tables tenant-scopées jointes, deux filtres — vérifiés
            // séparément par mutation. `bl.association_id` est le filet qui
            // compte vraiment ici : sans lui, une ligne de budget posée par une
            // AUTRE association sur un compte DU TENANT COURANT verrait son
            // montant additionné dans le SUM ci-dessous, gonflant le budget
            // affiché (test verrouillé). `c.association_id` défend le cas
            // symétrique — une ligne du tenant courant pointant par erreur un
            // compte étranger — mais ce cas est de toute façon absorbé en aval
            // par le filtre propre de metaComptes() (pas de méta = pas de
            // ligne) ; il reste posé par cohérence avec la convention de la
            // maison, pas parce qu'un test peut prouver qu'il est seul en jeu.
            ->where('bl.association_id', TenantContext::currentId())
            ->where('c.association_id', TenantContext::currentId())
            ->select([
                'bl.operation_id',
                'c.id as compte_id',
                DB::raw('SUM(bl.montant_prevu) as montant'),
            ])
            ->groupBy('bl.operation_id', 'c.id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->operation_id][(int) $row->compte_id] = (float) $row->montant;
        }

        return $map;
    }

    /**
     * Réalisé, pivoté depuis la carte du BudgetService.
     *
     * `realiseParCompteEtOperation()` rend compte_id => [operation_id => montant] ;
     * ce builder raisonne par opération. On pivote plutôt que d'écrire une
     * seconde requête : la logique Q1/Q2 et le signe des contra-comptes y sont
     * déjà résolus, une deuxième implémentation redivergerait.
     *
     * @param  list<int>  $operationIds
     * @return array<int, array<int, float>>
     */
    private function realiseParOperationEtCompte(int $exercice, array $operationIds): array
    {
        $retenues = array_flip(array_map('intval', $operationIds));
        $map = [];

        foreach ($this->budgetService->realiseParCompteEtOperation($exercice) as $compteId => $parOp) {
            foreach ($parOp as $opId => $montant) {
                if (! isset($retenues[(int) $opId])) {
                    continue;
                }
                $map[(int) $opId][(int) $compteId] = (float) $montant;
            }
        }

        return $map;
    }

    /**
     * Classe, famille et intitulé des comptes cités par au moins une source.
     *
     * Une seule requête pour les trois sources : la jointure de famille (préfixe
     * à deux chiffres du numéro PCG) est un piège qu'on ne veut écrire qu'une
     * fois — trois copies divergeraient, et un compte se retrouverait dans une
     * famille distincte de celle de ses homologues.
     *
     * Le scope tenant filtre ici aussi, et c'est LUI — avec le garde
     * `isset($meta[...])` en aval dans parOperations() — le seul rempart pour
     * le cas d'une PRÉVISION du tenant courant pointant par erreur un compte
     * étranger : {@see CompteResultatBuilder::fetchPrevisionsFlatEntries()}
     * scope `ep.association_id` et `op.association_id`, jamais le compte
     * lui-même. Une `encadrement_previsions` légitime mais mal pointée n'est
     * donc arrêtée nulle part avant d'arriver ici.
     * Le cas symétrique côté ventilation — une `budget_lines` légitime du
     * tenant courant pointant un compte étranger — n'atteint lui jamais ce
     * filtre : il est déjà écarté en amont par le `c.association_id` de
     * ventilations(), avant même que sa ligne ne soit unifiée avec les autres
     * sources. Le filtre posé ici pour ce cas-là est donc redondant, pas le
     * seul rempart.
     *
     * @param  list<int>  $compteIds
     * @return array<int, array{classe: int, famille_id: int, famille_nom: string, compte_nom: string}>
     */
    private function metaComptes(array $compteIds): array
    {
        if ($compteIds === []) {
            return [];
        }

        $rows = DB::table('comptes as c')
            ->leftJoin('familles as f', function ($join): void {
                $join->on('f.code', '=', DB::raw('SUBSTR(c.numero_pcg, 1, 2)'))
                    ->on('f.association_id', '=', 'c.association_id');
            })
            ->whereIn('c.id', $compteIds)
            ->whereIn('c.classe', [6, 7])
            ->where('c.association_id', TenantContext::currentId())
            ->select([
                'c.id as compte_id',
                'c.classe',
                'c.intitule as compte_nom',
                DB::raw('COALESCE(f.id, 0) as famille_id'),
                DB::raw("COALESCE(CONCAT(f.code, ' — ', f.nom), '(sans famille)') as famille_nom"),
            ])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->compte_id] = [
                'classe' => (int) $row->classe,
                'famille_id' => (int) $row->famille_id,
                'famille_nom' => (string) $row->famille_nom,
                'compte_nom' => (string) $row->compte_nom,
            ];
        }

        return $map;
    }

    /**
     * Tri des comptes, agrégats de famille, réindexation en liste.
     *
     * @param  array<int, array<string, mixed>>  $familles
     * @return list<array<string, mixed>>
     */
    private function finaliserSection(array $familles): array
    {
        $resultat = [];
        foreach ($familles as $famille) {
            usort(
                $famille['comptes'],
                fn (array $a, array $b): int => strcmp((string) $a['compte_nom'], (string) $b['compte_nom']),
            );
            $agregat = $this->agregerComptes($famille['comptes']);
            $resultat[] = array_merge($famille, $agregat);
        }

        usort(
            $resultat,
            fn (array $a, array $b): int => strcmp((string) $a['famille_nom'], (string) $b['famille_nom']),
        );

        return $resultat;
    }

    /**
     * Agrégat d'une liste de comptes. `null` si AUCUN enfant n'est renseigné,
     * sinon la somme des non-null — l'inverse ferait apparaître un 0,00 € là où
     * la vue doit laisser une case vide.
     *
     * @param  list<array<string, mixed>>  $comptes
     * @return array{budget: ?float, prevision: ?float, realise: float, hors_dotation: float}
     */
    private function agregerComptes(array $comptes): array
    {
        $budget = null;
        $prevision = null;
        $realise = 0.0;
        $horsDotation = 0.0;

        foreach ($comptes as $compte) {
            if ($compte['budget'] !== null) {
                $budget = round(($budget ?? 0.0) + $compte['budget'], 2);
            }
            if ($compte['prevision'] !== null) {
                $prevision = round(($prevision ?? 0.0) + $compte['prevision'], 2);
            }
            $realise = round($realise + $compte['realise'], 2);
            if ($compte['hors_dotation'] === true) {
                $horsDotation = round($horsDotation + $compte['realise'], 2);
            }
        }

        return [
            'budget' => $budget,
            'prevision' => $prevision,
            'realise' => $realise,
            'hors_dotation' => $horsDotation,
        ];
    }

    /**
     * Agrégat d'une section entière, par la même règle que les comptes.
     *
     * `hors_dotation` se REPORTE depuis les familles (où il est déjà un montant
     * cumulé), il ne se recalcule pas depuis un booléen qui n'existe plus à ce
     * niveau.
     *
     * @param  list<array<string, mixed>>  $familles
     * @return array{budget: ?float, prevision: ?float, realise: float, hors_dotation: float}
     */
    private function agreger(array $familles): array
    {
        $budget = null;
        $prevision = null;
        $realise = 0.0;
        $horsDotation = 0.0;

        foreach ($familles as $famille) {
            if ($famille['budget'] !== null) {
                $budget = round(($budget ?? 0.0) + $famille['budget'], 2);
            }
            if ($famille['prevision'] !== null) {
                $prevision = round(($prevision ?? 0.0) + $famille['prevision'], 2);
            }
            $realise = round($realise + $famille['realise'], 2);
            $horsDotation = round($horsDotation + $famille['hors_dotation'], 2);
        }

        return [
            'budget' => $budget,
            'prevision' => $prevision,
            'realise' => $realise,
            'hors_dotation' => $horsDotation,
        ];
    }
}
