<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\Transaction;
use App\Services\Compta\EtatReglementResolver;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * Chantier 4 — rempart anti-dérive du miroir statut_reglement.
 *
 * Parcourt les transactions métier (journaux vente et achat, cf.
 * `Transaction::scopeOperationnel`) de chaque tenant et compare la colonne
 * stockée au statut dérivé du ledger. --check : signale et sort en erreur si
 * divergence (CI/garde-fou). Sans --check : resynchronise via syncer.
 *
 * À jouer lors du cutover APRÈS `compta:backfill-partie-double` : tant que les
 * lignes partie double n'existent pas, le resolver retombe sur la colonne
 * stockée et la réconciliation est un no-op.
 */
final class ReconcilierStatutsCommand extends Command
{
    protected $signature = 'compta:reconcilier-statuts {--check : Signale les divergences sans corriger}';

    protected $description = 'Réconcilie statut_reglement (miroir) avec le statut dérivé du grand livre';

    public function handle(EtatReglementResolver $resolver): int
    {
        $check = (bool) $this->option('check');
        $divergences = 0;

        $associations = Association::query()->get();

        if ($associations->isEmpty()) {
            $this->info('Aucune association à traiter.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::current();

        try {
            foreach ($associations as $association) {
                TenantContext::clear();
                TenantContext::boot($association);

                // Périmètre métier : ventes et achats. Les écritures techniques
                // (T2/T4 du journal de banque, OD de compensation, à-nouveaux)
                // sont générées par le moteur et n'ont pas d'état de règlement
                // propre — les auditer produisait des divergences qui ne veulent
                // rien dire et noyaient les vraies.
                Transaction::query()->operationnel()->each(function (Transaction $tx) use ($resolver, $check, &$divergences): void {
                    $derive = $resolver->resolve($tx);

                    if ($tx->statut_reglement !== $derive) {
                        $divergences++;
                        $this->warn(sprintf(
                            'Tx #%d : miroir=%s ledger=%s',
                            (int) $tx->id,
                            $tx->statut_reglement->value,
                            $derive->value,
                        ));

                        if (! $check) {
                            $resolver->syncer($tx);
                        }
                    }
                });
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        if ($divergences === 0) {
            $this->info('Aucune divergence : miroir aligné sur le ledger.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d divergence(s) %s.', $divergences, $check ? 'détectée(s)' : 'corrigée(s)'));

        return $check ? self::FAILURE : self::SUCCESS;
    }
}
