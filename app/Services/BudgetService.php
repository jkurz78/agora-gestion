<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Compta\SensMontantPd;
use App\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Réalisé budgétaire, lu en partie double.
 *
 * Avant la V5 ce service lisait la colonne héritée `montant` et déduisait le sens
 * du `type` de la transaction. Cette formule comptait tout contra-compte à
 * l'envers — un 709 « Gratuités accordées » au débit d'un compte de classe 7
 * gonflait les produits au lieu de les réduire — et ignorait purement et
 * simplement toute écriture portée par une transaction d'un autre type. L'écran
 * Budget divergeait donc du compte de résultat sans que rien ne le signale.
 *
 * Les deux niveaux sont calculés indépendamment, chacun en une requête :
 *  - par compte : agrégation au niveau ligne, les affectations ne changeant pas
 *    le total d'un compte mais seulement sa répartition analytique ;
 *  - par compte et opération : union Q1 (lignes sans affectation) / Q2 (lignes
 *    éclatées), avec le signe emprunté à la ligne parente ({@see SensMontantPd}).
 *
 * Leur cohérence — total du compte = somme des ventilations + part non imputée —
 * est le test de non-régression du signe de Q2.
 */
final class BudgetService
{
    /** @var array<int, array<int, float>> mémoïsation par exercice */
    private array $cacheParCompte = [];

    /**
     * Réalisé de chaque compte de classe 6 et 7 sur l'exercice.
     *
     * Mémoïsé : {@see realise()} s'appuie dessus, et sans cache un appelant qui
     * boucle sur ses lignes déclencherait deux requêtes groupées par ligne —
     * pire que le N+1 qu'on supprime.
     *
     * @return array<int, float> compte_id => réalisé signé
     */
    public function realiseParCompte(int $exercice): array
    {
        return $this->cacheParCompte[$exercice] ??= $this->computeRealiseParCompte($exercice);
    }

    /** @return array<int, float> */
    private function computeRealiseParCompte(int $exercice): array
    {
        [$start, $end] = $this->bornes($exercice);
        $resultat = [];

        foreach ([6, 7] as $classe) {
            $rows = DB::table('transaction_lignes as tl')
                ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
                ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
                ->where('c.classe', $classe)
                ->whereNotNull('tl.compte_id')
                ->whereNull('tl.deleted_at')
                ->whereNull('tx.deleted_at')
                ->whereBetween('tx.date', [$start, $end])
                ->tap(fn (Builder $q) => $this->scopeTenant($q, 'c.association_id'))
                ->select(['c.id as compte_id', DB::raw(SensMontantPd::ligne($classe).' as montant')])
                ->groupBy('c.id')
                ->get();

            foreach ($rows as $row) {
                $resultat[(int) $row->compte_id] = round((float) $row->montant, 2);
            }
        }

        return $resultat;
    }

    /**
     * Réalisé par compte et par opération sur l'exercice.
     *
     * Les lignes non rattachées à une opération sont absentes de la carte : elles
     * relèvent du total du compte, pas d'une ventilation.
     *
     * @return array<int, array<int, float>> compte_id => [operation_id => réalisé signé]
     */
    public function realiseParCompteEtOperation(int $exercice): array
    {
        [$start, $end] = $this->bornes($exercice);
        $resultat = [];

        foreach ([6, 7] as $classe) {
            // Q1 — lignes sans affectation : l'opération vient de la ligne.
            $q1 = DB::table('transaction_lignes as tl')
                ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
                ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
                ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
                ->where('c.classe', $classe)
                ->whereNotNull('tl.compte_id')
                ->whereNotNull('tl.operation_id')
                ->whereNull('tla.id')
                ->whereNull('tl.deleted_at')
                ->whereNull('tx.deleted_at')
                ->whereBetween('tx.date', [$start, $end])
                ->tap(fn (Builder $q) => $this->scopeTenant($q, 'c.association_id'))
                ->select([
                    'c.id as compte_id',
                    'tl.operation_id',
                    DB::raw(SensMontantPd::ligne($classe).' as montant'),
                ])
                ->groupBy('c.id', 'tl.operation_id');

            // Q2 — lignes éclatées : l'opération et le montant viennent de
            // l'affectation, le signe de la ligne parente.
            $q2 = DB::table('transaction_ligne_affectations as tla')
                ->join('transaction_lignes as tl', 'tl.id', '=', 'tla.transaction_ligne_id')
                ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
                ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
                ->where('c.classe', $classe)
                ->whereNotNull('tl.compte_id')
                ->whereNotNull('tla.operation_id')
                ->whereNull('tl.deleted_at')
                ->whereNull('tx.deleted_at')
                ->whereBetween('tx.date', [$start, $end])
                ->tap(fn (Builder $q) => $this->scopeTenant($q, 'c.association_id'))
                ->select([
                    'c.id as compte_id',
                    'tla.operation_id',
                    DB::raw(SensMontantPd::affectation($classe, 'tl', 'tla').' as montant'),
                ])
                ->groupBy('c.id', 'tla.operation_id');

            foreach ([$q1->get(), $q2->get()] as $rows) {
                foreach ($rows as $row) {
                    $compteId = (int) $row->compte_id;
                    $operationId = (int) $row->operation_id;
                    $resultat[$compteId][$operationId]
                        = round(($resultat[$compteId][$operationId] ?? 0.0) + (float) $row->montant, 2);
                }
            }
        }

        return $resultat;
    }

    /**
     * Réalisé d'un compte sur un exercice.
     *
     * Conservée pour les appelants historiques, et sans danger grâce à la
     * mémoïsation. Préférer néanmoins {@see realiseParCompte()} dans toute
     * boucle : la carte s'y lit directement.
     */
    public function realise(int $compteId, int $exercice): float
    {
        return $this->realiseParCompte($exercice)[$compteId] ?? 0.0;
    }

    /** @return array{0: string, 1: string} */
    private function bornes(int $exercice): array
    {
        // Les bornes viennent du paramétrage du tenant, jamais d'un calcul local :
        // une association en exercice civil ou décalé n'a pas les mêmes dates.
        $range = app(ExerciceService::class)->dateRange($exercice);

        return [$range['start']->toDateString(), $range['end']->toDateString()];
    }

    private function scopeTenant(Builder $query, string $colonne): void
    {
        if (! TenantContext::hasBooted()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($colonne, TenantContext::currentId());
    }
}
