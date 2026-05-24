<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\Transaction;
use App\Services\Compta\BackfillAuditor;
use App\Services\Compta\TransactionConverter;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande artisan de backfill partie double (spec §4.3, sous-slice 1d).
 *
 * Convertit les transactions legacy (equilibree=FALSE) d'un exercice vers le modèle
 * partie double (transaction_lignes avec compte_id, debit, credit).
 *
 * Options :
 *   --exercice=current|YYYY  Exercice à convertir (défaut : exercice courant)
 *   --dry-run                Audit seulement — aucune écriture
 *   --force                  Re-conversion totale même si equilibree=TRUE (interdit en prod)
 *   --asso=ID                Limiter à une association (console interne only)
 *
 * Idempotent : skip si equilibree=TRUE (sauf --force).
 * Step 32 : squelette + dry-run + rapport
 * Step 33 : conversion idempotente + invariants + rollback
 * Step 34 : --force + reset + guard prod
 */
final class BackfillPartieDoubleCommand extends Command
{
    protected $signature = 'compta:backfill-partie-double
                            {--exercice=current : Exercice comptable à convertir (current ou YYYY)}
                            {--dry-run : Audit seulement, aucune écriture en base}
                            {--force : Re-conversion totale même si equilibree=TRUE (interdit en prod)}
                            {--asso= : Limiter à une association (ID)}';

    protected $description = 'Backfill partie double : convertit l\'exercice legacy vers le modèle double-entrée.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly BackfillAuditor $auditor,
        private readonly TransactionConverter $converter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // -- Guard --force en production --
        if ($this->option('force') && app()->environment('production')) {
            $this->error('--force est interdit en production. Utilisez le mode staging ou testing.');

            return self::FAILURE;
        }

        // -- Résolution de l'exercice --
        $exerciceOption = $this->option('exercice') ?: 'current';
        $annee = $exerciceOption === 'current'
            ? $this->exerciceService->current()
            : (int) $exerciceOption;

        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        // -- Résolution des associations à traiter --
        $assoOption = $this->option('asso');
        $associations = $assoOption !== null
            ? Association::query()->whereKey((int) $assoOption)->get()
            : Association::query()->get();

        if ($associations->isEmpty()) {
            $this->warn('Aucune association à traiter.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::current();

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                if ($isDryRun) {
                    $this->runDryRun($annee);
                } else {
                    $this->runConversion($annee, $isForce);
                }
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // Dry-run
    // =========================================================================

    private function runDryRun(int $annee): void
    {
        $assoId = (int) TenantContext::currentId();
        $rapport = $this->auditor->auditer($assoId, $annee);

        $this->line('');
        $this->info('═══════════════════════════════════════');
        $this->info('  RAPPORT DRY-RUN — Backfill Partie Double');
        $this->info('  Association #'.$assoId.' — Exercice '.$annee);
        $this->info('═══════════════════════════════════════');
        $this->line('');

        $this->info(sprintf(
            '%d transactions à convertir (equilibree=FALSE) dans l\'exercice %d',
            $rapport['nb_transactions_a_convertir'],
            $annee
        ));

        $this->line('');
        $this->line('Sous-catégories sans code_cerfa ('.count($rapport['sc_sans_code_cerfa']).')');
        if (! empty($rapport['sc_sans_code_cerfa'])) {
            $this->table(['ID', 'Nom'], array_map(
                fn (array $sc): array => [$sc['id'], $sc['nom']],
                $rapport['sc_sans_code_cerfa']
            ));
        } else {
            $this->line('  (aucune)');
        }

        $this->line('');
        $this->line('Modes non couverts ('.$rapport['modes_non_couverts_count'].')');
        if (! empty($rapport['modes_non_couverts'])) {
            $this->table(['Mode', 'Nb transactions'], array_map(
                fn (array $m): array => [$m['mode_paiement'], $m['count']],
                $rapport['modes_non_couverts']
            ));
        } else {
            $this->line('  (aucun)');
        }

        $this->line('');
        if ($rapport['nb_transactions_a_convertir'] === 0) {
            $this->info('Aucune transaction à convertir — exercice déjà à jour.');
        } else {
            $this->warn("Dry-run terminé : {$rapport['nb_transactions_a_convertir']} transaction(s) à convertir. Relancer sans --dry-run pour effectuer la conversion.");
        }
    }

    // =========================================================================
    // Conversion réelle (Step 33)
    // =========================================================================

    private function runConversion(int $annee, bool $isForce): void
    {
        $assoId = (int) TenantContext::currentId();
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        $this->info("Backfill exercice {$annee} — association #{$assoId}");

        // Charger les transactions à convertir
        $query = Transaction::whereBetween('date', [$dateDebut, $dateFin]);

        if (! $isForce) {
            // Idempotence : skip si equilibree=TRUE
            $query->where(function ($q) {
                $q->where('equilibree', false)->orWhereNull('equilibree');
            });
        }

        $transactions = $query->get();
        $total = $transactions->count();

        if ($total === 0) {
            $this->info("X already up to date, 0 converted.");

            return;
        }

        $this->info("{$total} transaction(s) à convertir...");

        $converted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($transactions as $tx) {
            try {
                DB::transaction(function () use ($tx, &$converted, &$skipped) {
                    $result = $this->converter->convertir($tx);

                    if ($result) {
                        $converted++;
                        Log::info('[Backfill] Transaction convertie', ['transaction_id' => $tx->id]);
                    } else {
                        $skipped++;
                        Log::info('[Backfill] Transaction skippée (sans tiers ou SC sans code)', ['transaction_id' => $tx->id]);
                    }
                });
            } catch (\Throwable $e) {
                $errors++;
                Log::error('[Backfill] Erreur lors de la conversion', [
                    'transaction_id' => $tx->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  Erreur Tx #{$tx->id} : {$e->getMessage()}");
            }
        }

        $this->info("Backfill terminé : {$converted} convertie(s), {$skipped} skippée(s), {$errors} erreur(s).");

        if ($errors > 0) {
            $this->warn("Des erreurs sont survenues. Consulter les logs pour les détails.");
        }
    }
}
