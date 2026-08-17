<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * SEL-01 — Opérations éligibles au compte de résultat par opérations.
 *
 * Une opération est éligible dès qu'elle porte au moins un mouvement RÉEL de
 * résultat dans l'exercice affiché : une ligne directe de classe 6 ou 7, ou une
 * affectation ventilée dont le compte de la ligne parente est de classe 6 ou 7.
 *
 * Ce qui n'entre PAS dans le critère, volontairement : le statut de
 * l'opération, ses dates, l'état actif de son type. Une opération clôturée
 * l'an dernier mais qui reçoit un règlement tardif cette année reste
 * consultable ; une opération en cours sans le moindre mouvement ne pollue pas
 * le sélecteur.
 *
 * Les deux branches reprennent l'invariant Q1/Q2 de CompteResultatBuilder pour
 * ne jamais compter une ligne ventilée par ses deux bouts. Ici seule
 * l'existence compte, mais garder la même forme évite qu'elles divergent.
 */
final class OperationsEligiblesQuery
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Identifiants d'opérations ayant un mouvement de résultat sur l'exercice.
     *
     * @return list<int> triés, sans doublon
     */
    public function pourExercice(int $exercice): array
    {
        if (! TenantContext::hasBooted()) {
            return [];
        }

        $tenantId = TenantContext::currentId();
        $range = $this->exerciceService->dateRange($exercice);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        // Q1 : lignes directes sans affectations.
        $q1 = DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
            ->whereIn('c.classe', [6, 7])
            ->whereNull('tla.id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNotNull('tl.operation_id')
            ->whereBetween('tx.date', [$start, $end])
            ->where('c.association_id', $tenantId)
            ->select('tl.operation_id as operation_id');

        // Q2 : affectations, jointes à leur ligne et au compte parent.
        $q2 = DB::table('transaction_ligne_affectations as tla2')
            ->join('transaction_lignes as tl', 'tl.id', '=', 'tla2.transaction_ligne_id')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->whereIn('c.classe', [6, 7])
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNotNull('tla2.operation_id')
            ->whereBetween('tx.date', [$start, $end])
            ->where('c.association_id', $tenantId)
            ->select('tla2.operation_id as operation_id');

        return $q1->union($q2)
            ->pluck('operation_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Intersection d'une sélection non fiable (URL, formulaire) avec les
     * opérations éligibles — SEL-04.
     *
     * @param  array<mixed>  $selection
     * @return list<int>
     */
    public function normaliser(array $selection, int $exercice): array
    {
        $demandes = collect($selection)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->all();

        if ($demandes === []) {
            return [];
        }

        return array_values(array_intersect($demandes, $this->pourExercice($exercice)));
    }
}
