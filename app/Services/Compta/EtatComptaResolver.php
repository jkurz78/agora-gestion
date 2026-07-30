<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\EtapeCompta;
use App\Models\Transaction;

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
        $blocages = [];

        $legacy = $this->transactionsHorsPartieDouble();
        if ($legacy > 0) {
            $blocages[EtapeCompta::BackfillRequis->value] = sprintf(
                '%d transaction(s) ne sont pas converties en partie double.',
                $legacy,
            );
        }

        return new EtatCompta($blocages);
    }

    /**
     * Critère du backfill lui-même (equilibree = false), avec l'exclusion
     * HelloAsso d'assert-pd-complete : ces transactions restent legacy par
     * construction, leur enrichissement PD est best-effort au sync.
     */
    private function transactionsHorsPartieDouble(): int
    {
        return Transaction::query()
            ->whereNull('helloasso_order_id')
            ->where('equilibree', false)
            ->count();
    }
}
