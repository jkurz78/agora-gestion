<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * G.2 — garde-fou CI pour la complétude partie double.
 *
 * Parcourt toutes les transactions non-HelloAsso et vérifie :
 *   1. equilibree === true
 *   2. Au moins une ligne avec compte_id non nul
 *   3. Sum(debit) === Sum(credit) sur ces lignes
 *
 * --check : signale et sort en erreur si incomplétude détectée (CI).
 * Sans --check : signale et sort toujours en succès.
 */
final class AssertPDCompleteCommand extends Command
{
    protected $signature = 'compta:assert-pd-complete {--check : Exit 1 si transactions non-PD détectées}';

    protected $description = 'Vérifie que toutes les transactions non-HelloAsso ont des écritures PD équilibrées';

    public function handle(): int
    {
        $check = (bool) $this->option('check');
        $incomplete = 0;

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

                Transaction::query()
                    ->whereNull('helloasso_order_id')
                    ->with('lignes')
                    ->each(function (Transaction $tx) use (&$incomplete): void {
                        $reason = $this->detectIncomplete($tx);

                        if ($reason !== null) {
                            $incomplete++;
                            $this->warn(sprintf('Tx #%d : %s', (int) $tx->id, $reason));
                        }
                    });
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        if ($incomplete === 0) {
            $this->info('Toutes les transactions sont PD-conformes.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d transaction(s) incomplète(s)', $incomplete));

        return $check ? self::FAILURE : self::SUCCESS;
    }

    private function detectIncomplete(Transaction $tx): ?string
    {
        if ($tx->equilibree !== true) {
            return 'equilibree !== true';
        }

        $lignesAvecCompte = $tx->lignes->filter(fn ($l) => $l->compte_id !== null);

        if ($lignesAvecCompte->isEmpty()) {
            return 'aucune ligne avec compte_id';
        }

        $sumDebit = $lignesAvecCompte->sum(fn ($l) => (float) $l->debit);
        $sumCredit = $lignesAvecCompte->sum(fn ($l) => (float) $l->credit);

        if (abs($sumDebit - $sumCredit) > 0.001) {
            return sprintf('déséquilibre débit=%.2f crédit=%.2f', $sumDebit, $sumCredit);
        }

        return null;
    }
}
