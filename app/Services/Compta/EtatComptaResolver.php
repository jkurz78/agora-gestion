<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use RuntimeException;

/**
 * Déduit l'étape comptable du tenant courant à partir des données.
 *
 * Ne prend pas d'association en paramètre : les modèles étant protégés par un
 * scope global fail-closed sur association_id, c'est à l'appelant de booter
 * TenantContext — comme le font compta:check-integrity et
 * compta:reconcilier-statuts en itérant sur les associations.
 *
 * Lecture seule : ce service n'écrit rien et ne corrige rien.
 */
final class EtatComptaResolver
{
    public function pourTenantCourant(): EtatCompta
    {
        // Fail-closed. TenantScope injecte `1 = 0` quand le contexte n'est pas
        // booté : sans ce garde-fou, le résolveur ne verrait aucune donnée, se
        // déclarerait opérationnel, et toutes les gardes qui s'appuient sur lui
        // laisseraient passer l'opération. Pour la seule classe du chantier dont
        // le métier est de refuser, échouer en s'ouvrant est le mauvais sens.
        if (! TenantContext::hasBooted()) {
            throw new RuntimeException(
                'EtatComptaResolver exige un TenantContext booté : sans lui, aucune donnée n’est visible et l’état serait faussement opérationnel.'
            );
        }

        $blocages = [];

        $nonConverties = $this->transactionsHorsPartieDouble();
        if ($nonConverties > 0) {
            $blocages[EtapeCompta::BackfillRequis->value] = sprintf(
                '%d opération(s) n’ont pas d’écriture comptable complète.',
                $nonConverties,
            );
        }

        return new EtatCompta($blocages);
    }

    /**
     * Critère du backfill lui-même (equilibree = false), avec l'exclusion
     * HelloAsso d'assert-pd-complete : ces transactions restent legacy par
     * construction, leur enrichissement PD est best-effort au sync.
     *
     * Le critère se limite volontairement au drapeau `equilibree`, là où
     * compta:assert-pd-complete vérifie en plus la présence de lignes et
     * l'équilibre débit/crédit de chacune. Un contrôle par requête ne peut pas
     * payer ce coût ; le critère complet reste appliqué au déploiement.
     */
    private function transactionsHorsPartieDouble(): int
    {
        return Transaction::query()
            ->whereNull('helloasso_order_id')
            ->where('equilibree', false)
            ->count();
    }
}
