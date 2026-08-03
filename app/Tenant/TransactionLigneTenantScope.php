<?php

declare(strict_types=1);

namespace App\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Scope d'isolation tenant pour transaction_lignes (audit #8).
 *
 * Contrairement aux autres modèles tenant (TenantScope filtre sur la colonne
 * association_id), transaction_lignes ne porte PAS de colonne association_id :
 * la tenancy est dérivée de la transaction parente, qui reste la SOURCE UNIQUE
 * de vérité. Choix délibéré (cf. FactureLigne, même pattern table-enfant) :
 * pas de dénormalisation → aucune divergence ligne/transaction possible, donc
 * aucune fuite cross-tenant « auto-entretenue » par un association_id local faux.
 *
 * Filtre appliqué : transaction_lignes.transaction_id ∈ (SELECT id FROM
 * transactions WHERE association_id = tenant courant).
 *
 * Fail-closed : si TenantContext n'est pas booté → WHERE 1 = 0 (aucune ligne),
 * conformément à CLAUDE.md (scope global fail-closed sur les modèles financiers).
 *
 * La sous-requête interroge la table `transactions` en SQL brut (sans les global
 * scopes Eloquent de Transaction), pour ne PAS coupler l'isolation au SoftDelete
 * de la transaction : une ligne dont la transaction est soft-deleted reste
 * visible pour le tenant propriétaire (comportement historique préservé).
 */
final class TransactionLigneTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! TenantContext::hasBooted()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn(
            $model->getTable().'.transaction_id',
            function (QueryBuilder $sub): void {
                $sub->select('id')
                    ->from('transactions')
                    ->where('association_id', TenantContext::currentId());
            }
        );
    }
}
