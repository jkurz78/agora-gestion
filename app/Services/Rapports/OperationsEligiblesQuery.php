<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
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
 *
 * ── $avecPrevisions — la tension SEL-01 / EX-03 ─────────────────────────────
 *
 * SEL-01 (ci-dessus) ne veut que du réel : une opération sans mouvement ne
 * pollue pas le sélecteur. Mais EX-03 (dimension exercice du prévisionnel)
 * exige l'inverse pour la projection : une opération pluriannuelle dont la
 * troisième année est planifiée mais non commencée doit montrer cette
 * troisième année — construire la liste sur les seuls mouvements réels la
 * rendrait insélectionnable, c'est-à-dire viderait la projection de son objet.
 *
 * Les deux exigences sont contradictoires prises isolément ; $avecPrevisions
 * les concilie en les rendant mutuellement exclusives selon le mode d'écran.
 * En mode réalisé, le critère reste SEL-01 pur — aucune raison d'y assouplir
 * quoi que ce soit, le prévisionnel n'y est même pas affiché. En mode
 * projection, on ajoute les deux sources de prévisionnel (charges via
 * encadrement_previsions, produits via reglements.montant_prevu) à l'union :
 * une opération devient éligible si elle porte un mouvement réel OU une
 * prévision rattachée à l'exercice. C'est pourquoi l'appelant (le Livewire du
 * rapport) ne passe `true` que lorsque le mode projection est actif — jamais
 * en mode réalisé.
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
    public function pourExercice(int $exercice, bool $avecPrevisions = false): array
    {
        if (! TenantContext::hasBooted()) {
            return [];
        }

        $tenantId = TenantContext::currentId();
        $range = $this->exerciceService->dateRange($exercice);
        $start = $range['start']->toDateString();
        $end = $range['end']->toDateString();

        // La jointure sur `operations` n'est pas décorative : Operation utilise
        // SoftDeletes, et une suppression logique ne déclenche aucune action de
        // clé étrangère — les lignes continuent de pointer dessus. Sans ce
        // filtre, une opération supprimée resterait « éligible » ici, tout en
        // étant absente de l'arbre du sélecteur (qui passe par Eloquent, donc
        // par le scope SoftDeletes) : normaliser() accepterait un id que
        // l'écran ne propose plus, et le rapport calculerait ses montants.
        $q1 = DB::table('transaction_lignes as tl')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->join('operations as o', 'o.id', '=', 'tl.operation_id')
            ->leftJoin('transaction_ligne_affectations as tla', 'tla.transaction_ligne_id', '=', 'tl.id')
            ->whereIn('c.classe', [6, 7])
            ->whereNull('tla.id')
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereBetween('tx.date', [$start, $end])
            ->where('c.association_id', $tenantId)
            ->where('o.association_id', $tenantId)
            ->select('tl.operation_id as operation_id');

        // Q2 : affectations, jointes à leur ligne et au compte parent.
        $q2 = DB::table('transaction_ligne_affectations as tla2')
            ->join('transaction_lignes as tl', 'tl.id', '=', 'tla2.transaction_ligne_id')
            ->join('comptes as c', 'tl.compte_id', '=', 'c.id')
            ->join('transactions as tx', 'tl.transaction_id', '=', 'tx.id')
            ->join('operations as o', 'o.id', '=', 'tla2.operation_id')
            ->whereIn('c.classe', [6, 7])
            ->whereNull('tl.deleted_at')
            ->whereNull('tx.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereBetween('tx.date', [$start, $end])
            ->where('c.association_id', $tenantId)
            ->where('o.association_id', $tenantId)
            ->select('tla2.operation_id as operation_id');

        $union = $q1->union($q2);

        if ($avecPrevisions) {
            $union
                ->union($this->previsionsCharges($tenantId, $start, $end))
                ->union($this->previsionsProduits($tenantId, $start, $end));
        }

        return $union
            ->pluck('operation_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Branche prévisionnelle « charges » : encadrement_previsions, rattachée à
     * l'exercice par la date de sa séance, pas par les dates de l'opération.
     * Jointures calquées sur CompteResultatBuilder::buildPrevisionsCharges()
     * pour ne pas diverger de la lecture faite une fois l'opération retenue.
     *
     * `s.date` est nullable (séance non encore planifiée) : le groupe
     * « Exercice non déterminé » reste visible dans l'exercice affiché, donc
     * une prévision sans date de séance rend son opération éligible ici aussi
     * — d'où le OR entre whereNull et whereBetween, impérativement parenthésé
     * dans une closure pour ne pas se détacher de la conjonction association
     * /suppression logique qui l'entoure (sinon : fuite inter-tenant ou
     * inter-opération).
     */
    private function previsionsCharges(int $tenantId, string $start, string $end): Builder
    {
        return DB::table('encadrement_previsions as ep')
            ->join('comptes as cpt', 'cpt.id', '=', 'ep.compte_id')
            ->join('seances as s', 's.id', '=', 'ep.seance_id')
            ->join('operations as o', 'o.id', '=', 'ep.operation_id')
            ->whereIn('cpt.classe', [6, 7])
            ->whereNull('o.deleted_at')
            ->where('ep.association_id', $tenantId)
            ->where('o.association_id', $tenantId)
            ->where(function (Builder $query) use ($start, $end): void {
                $query->whereNull('s.date')
                    ->orWhereBetween('s.date', [$start, $end]);
            })
            ->select('ep.operation_id as operation_id');
    }

    /**
     * Branche prévisionnelle « produits » : reglements.montant_prevu, même
     * logique de rattachement par la date de séance que previsionsCharges().
     * Jointures calquées sur CompteResultatBuilder::buildPrevisionsProduits().
     */
    private function previsionsProduits(int $tenantId, string $start, string $end): Builder
    {
        return DB::table('reglements as r')
            ->join('participants as p', 'p.id', '=', 'r.participant_id')
            ->join('operations as op', 'op.id', '=', 'p.operation_id')
            ->join('type_operations as to_', 'to_.id', '=', 'op.type_operation_id')
            ->join('comptes as cpt', 'cpt.id', '=', 'to_.compte_id')
            ->join('seances as s', 's.id', '=', 'r.seance_id')
            ->whereIn('cpt.classe', [6, 7])
            ->where('r.montant_prevu', '>', 0)
            ->whereNull('op.deleted_at')
            ->where('op.association_id', $tenantId)
            ->where(function (Builder $query) use ($start, $end): void {
                $query->whereNull('s.date')
                    ->orWhereBetween('s.date', [$start, $end]);
            })
            ->select('p.operation_id as operation_id');
    }

    /**
     * Intersection d'une sélection non fiable (URL, formulaire) avec les
     * opérations éligibles — SEL-04.
     *
     * $avecPrevisions se propage tel quel vers pourExercice() : la
     * normalisation d'une sélection doit accepter exactement les mêmes ids que
     * l'arbre qui les propose, mode par mode.
     *
     * @param  array<mixed>  $selection
     * @return list<int>
     */
    public function normaliser(array $selection, int $exercice, bool $avecPrevisions = false): array
    {
        $demandes = collect($selection)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->all();

        if ($demandes === []) {
            return [];
        }

        return array_values(array_intersect($demandes, $this->pourExercice($exercice, $avecPrevisions)));
    }
}
