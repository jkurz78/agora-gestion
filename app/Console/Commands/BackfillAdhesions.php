<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\Adhesion;
use App\Models\Association;
use App\Models\Transaction;
use App\Services\AdhesionService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;

final class BackfillAdhesions extends Command
{
    protected $signature = 'adhesions:backfill {--dry-run : ne crée rien, log seulement}';

    protected $description = 'Génère les adhésions manquantes à partir des transactions cotisations existantes';

    public function handle(AdhesionService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $associations = Association::all();
        $totalCreated = 0;
        $totalScanned = 0;

        foreach ($associations as $asso) {
            TenantContext::clear();
            TenantContext::boot($asso);

            $this->info("Association #{$asso->id} ({$asso->nom})");

            Transaction::where('type', TypeTransaction::Recette->value)
                ->whereHas('lignes.sousCategorie.usages', function ($q): void {
                    $q->where('usage', UsageComptable::Cotisation->value);
                })
                ->chunkById(500, function ($transactions) use ($service, $dryRun, &$totalCreated, &$totalScanned): void {
                    foreach ($transactions as $tx) {
                        $totalScanned++;

                        if ($dryRun) {
                            $exists = Adhesion::where('transaction_id', (int) $tx->id)->exists();
                            if (! $exists) {
                                $totalCreated++;
                                $this->line("[dry-run] Transaction #{$tx->id} → adhésion manquante");
                            }

                            continue;
                        }

                        $before = Adhesion::where('transaction_id', (int) $tx->id)->count();
                        $service->creerDepuisTransaction($tx);
                        $after = Adhesion::where('transaction_id', (int) $tx->id)->count();

                        if ($after > $before) {
                            $totalCreated++;
                        }
                    }
                });
        }

        $verb = $dryRun ? '[dry-run] créeraient' : 'créées';
        $this->info("Terminé. {$totalCreated} adhésions {$verb} sur {$totalScanned} transactions scannées.");

        return self::SUCCESS;
    }
}
