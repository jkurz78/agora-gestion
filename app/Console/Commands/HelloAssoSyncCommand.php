<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\HelloAssoParametres;
use App\Services\ExerciceService;
use App\Services\HelloAssoApiClient;
use App\Services\HelloAssoSyncService;
use App\Support\Demo;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HelloAssoSyncCommand extends Command
{
    protected $signature = 'helloasso:sync {--exercice= : Année de l\'exercice à synchroniser (défaut : exercice courant)}';

    protected $description = 'Synchronise les commandes HelloAsso pour tous les tenants configurés';

    public function handle(): int
    {
        if (Demo::isActive()) {
            Log::info('helloasso.sync.skipped_demo');

            return self::SUCCESS;
        }

        $paramsList = HelloAssoParametres::query()
            ->with('association')
            ->get();

        if ($paramsList->isEmpty()) {
            $this->info('helloasso:sync : aucun tenant avec HelloAsso configuré.');

            return self::SUCCESS;
        }

        $hasFailure = false;

        foreach ($paramsList as $parametres) {
            $association = $parametres->association;
            if ($association === null) {
                $this->warn("Paramètres HelloAsso orphelins (association introuvable) id={$parametres->id}");
                $hasFailure = true;

                continue;
            }

            try {
                TenantContext::boot($association);
                $this->syncTenant($parametres, $association);
            } catch (Throwable $e) {
                $this->error("Erreur sync HelloAsso pour {$association->nom} : {$e->getMessage()}");
                Log::error('helloasso.sync.error', [
                    'association_id' => $association->id,
                    'message' => $e->getMessage(),
                ]);
                $hasFailure = true;
            } finally {
                TenantContext::clear();
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    private function syncTenant(HelloAssoParametres $parametres, Association $association): void
    {
        $exerciceArg = $this->option('exercice');
        $exercice = $exerciceArg !== null ? (int) $exerciceArg : app(ExerciceService::class)->current();

        $apiClient = new HelloAssoApiClient($parametres);
        $syncService = new HelloAssoSyncService($parametres);

        $dateRange = app(ExerciceService::class)->dateRange($exercice);
        $from = $dateRange['start']->toDateString();
        $to = $dateRange['end']->toDateString();

        $orders = $apiClient->fetchOrders($from, $to);
        $result = $syncService->synchroniser($orders, $exercice);

        $this->info(sprintf(
            '[%s] Sync OK — %d tx créées, %d mises à jour, %d skipped, %d erreurs',
            $association->nom,
            $result->transactionsCreated,
            $result->transactionsUpdated,
            $result->ordersSkipped,
            count($result->errors),
        ));

        Log::info('helloasso.sync.done', [
            'association_id' => $association->id,
            'exercice' => $exercice,
            'transactions_created' => $result->transactionsCreated,
            'transactions_updated' => $result->transactionsUpdated,
            'orders_skipped' => $result->ordersSkipped,
            'errors' => $result->errors,
        ]);
    }
}
