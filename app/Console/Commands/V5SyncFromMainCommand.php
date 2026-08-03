<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Commande artisan de synchronisation hebdomadaire feat/compta-v5 depuis main.
 *
 * Workflow (spec §16.2, sous-slice 1d) :
 *   1. git fetch origin main
 *   2. git merge origin/main --no-edit
 *   3. php artisan test --filter=Backfill
 *   4. php artisan compta:backfill-partie-double --dry-run
 *
 * Mode --dry-run : affiche « would run: <cmd> » sans exécuter.
 * Exit code 0 : toutes les étapes réussies.
 * Exit code 1 : au moins une étape échouée.
 */
final class V5SyncFromMainCommand extends Command
{
    protected $signature = 'v5:sync-from-main
                            {--dry-run : Affiche les commandes sans les exécuter}';

    protected $description = 'Synchronise feat/compta-v5 depuis main et valide les tests Backfill.';

    /** @var list<array{etape: string, commande: string, statut: string, duree: string}> */
    private array $rapport = [];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            $this->info('[dry-run] Mode dry-run activé — aucune commande réelle exécutée.');
        }

        $etapes = [
            ['label' => 'git fetch origin main', 'cmd' => 'git fetch origin main'],
            ['label' => 'git merge origin/main --no-edit', 'cmd' => 'git merge origin/main --no-edit'],
            ['label' => 'php artisan test --filter=Backfill', 'cmd' => 'php artisan test --filter=Backfill'],
            ['label' => 'compta:backfill-partie-double --dry-run', 'cmd' => 'php artisan compta:backfill-partie-double --dry-run'],
        ];

        $hasFailure = false;

        foreach ($etapes as $etape) {
            $debut = Carbon::now();

            if ($isDryRun) {
                $this->line("would run: {$etape['cmd']}");
                $this->rapport[] = [
                    'etape' => $etape['label'],
                    'statut' => 'dry-run',
                    'duree' => '0ms',
                ];

                continue;
            }

            $result = Process::run($etape['cmd']);
            $dureeMs = (int) ($debut->diffInMilliseconds(Carbon::now()));

            if ($result->successful()) {
                $statut = 'OK';
                $this->info("[OK] {$etape['label']}");
                if ($result->output()) {
                    $this->line($result->output());
                }
            } else {
                $statut = 'ÉCHOUÉ';
                $hasFailure = true;
                $this->error("[ÉCHOUÉ] {$etape['label']}");
                if ($result->output()) {
                    $this->line($result->output());
                }
                if ($result->errorOutput()) {
                    $this->line($result->errorOutput());
                }

                $this->rapport[] = [
                    'etape' => $etape['label'],
                    'statut' => $statut,
                    'duree' => "{$dureeMs}ms",
                ];

                // Arrêt immédiat sur échec
                break;
            }

            $this->rapport[] = [
                'etape' => $etape['label'],
                'statut' => $statut,
                'duree' => "{$dureeMs}ms",
            ];
        }

        $this->afficherRapport();

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    private function afficherRapport(): void
    {
        $this->line('');
        $this->line('─── Rapport v5:sync-from-main ───────────────────────────────');

        $rows = array_map(
            fn (array $r): array => [$r['etape'], $r['statut'], $r['duree']],
            $this->rapport
        );

        $this->table(['Étape', 'Statut', 'Durée'], $rows);
    }
}
