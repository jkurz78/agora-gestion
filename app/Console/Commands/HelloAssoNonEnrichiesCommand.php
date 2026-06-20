<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\Transaction;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

/**
 * Liste les transactions HelloAsso restées en mode legacy (PD non enrichie).
 *
 * Les transactions HelloAsso sont enrichies en partie double au sync via
 * TransactionConverter (best-effort). En cas d'échec (sous-catégorie sans
 * code_cerfa, compte 6xx/7xx manquant…), la transaction reste legacy : elle
 * est invisible dans les restitutions PD ET exclue de la garde compta:assert-pd-complete.
 *
 * Cette commande rend cet angle mort visible — lecture seule, aucun effet de bord.
 */
final class HelloAssoNonEnrichiesCommand extends Command
{
    protected $signature = 'compta:helloasso-non-enrichies';

    protected $description = 'Liste les transactions HelloAsso non enrichies en partie double (restées legacy)';

    public function handle(): int
    {
        $associations = Association::query()->get();

        if ($associations->isEmpty()) {
            $this->info('Aucune association à traiter.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::current();
        $rows = [];

        try {
            foreach ($associations as $association) {
                TenantContext::clear();
                TenantContext::boot($association);

                Transaction::query()
                    ->whereNotNull('helloasso_order_id')
                    ->where('equilibree', false)
                    ->with('tiers')
                    ->orderBy('date')
                    ->each(function (Transaction $tx) use (&$rows, $association): void {
                        $rows[] = [
                            (string) $association->nom,
                            (int) $tx->id,
                            $tx->date instanceof \DateTimeInterface ? $tx->date->format('Y-m-d') : (string) $tx->date,
                            (string) $tx->libelle,
                            number_format((float) $tx->montant_total, 2, ',', ' ').' €',
                            $tx->tiers?->displayName() ?? '—',
                        ];
                    });
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        if ($rows === []) {
            $this->info('Aucune transaction HelloAsso non enrichie — base saine.');

            return self::SUCCESS;
        }

        $this->table(
            ['Association', 'Tx #', 'Date', 'Libellé', 'Montant', 'Tiers'],
            $rows,
        );
        $this->warn(sprintf('%d transaction(s) HelloAsso restée(s) en legacy.', count($rows)));

        return self::SUCCESS;
    }
}
