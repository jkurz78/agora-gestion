<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\RapprochementBancaire;
use App\Models\TransactionLigne;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Vérifie l'intégrité comptable de chaque association :
 *
 *  1. Rapprochements verrouillés → écart = 0
 *  2. Remises bancaires → montant_total T4 = sum des sources
 *  3. Transactions → montant_total = net débit/crédit des ventilations 6/7
 *  4. Adhésions → montant_facial = sum(lignes de la TX liée)
 *
 * Stocke les résultats en cache pour affichage d'une alerte admin dans l'UI.
 * Exit code 1 si au moins une anomalie détectée.
 */
final class ComptaCheckIntegrityCommand extends Command
{
    protected $signature = 'compta:check-integrity {--fix : Tenter de corriger les montant_total TX divergents} {--quiet-ok : Ne rien afficher si tout est OK}';

    protected $description = 'Vérifie l\'intégrité comptable (rapprochements, remises, TX, adhésions)';

    /** @var list<string> */
    private array $issues = [];

    public function handle(RapprochementBancaireService $rapprochementService): int
    {
        $associations = Association::all();
        $globalIssues = false;

        foreach ($associations as $association) {
            TenantContext::boot($association);
            $this->issues = [];

            $this->checkRapprochements($rapprochementService);
            $this->checkRemises();
            $this->checkTransactionTotals();
            $this->checkAdhesionMontants();

            // Stocker en cache pour l'alerte UI (TTL 25h — le cron tourne toutes les 24h)
            $cacheKey = "compta:integrity:{$association->id}";
            Cache::put($cacheKey, [
                'checked_at' => now()->toIso8601String(),
                'issues' => $this->issues,
            ], now()->addHours(25));

            if (! empty($this->issues)) {
                $globalIssues = true;
                $this->error("Association #{$association->id} ({$association->nom}) — ".count($this->issues).' anomalie(s) :');
                foreach ($this->issues as $issue) {
                    $this->line("  ⚠️  {$issue}");
                }
            } elseif (! $this->option('quiet-ok')) {
                $this->info("Association #{$association->id} ({$association->nom}) — ✓ intégrité OK");
            }
        }

        if ($globalIssues) {
            $this->newLine();
            $this->error('Des anomalies ont été détectées. Voir ci-dessus.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * CHECK 1 — Tous les rapprochements verrouillés doivent avoir un écart de 0.
     */
    private function checkRapprochements(RapprochementBancaireService $rapprochementService): void
    {
        $rapprochements = RapprochementBancaire::query()
            ->where('statut', 'verrouille')
            ->where('association_id', TenantContext::currentId())
            ->get();

        foreach ($rapprochements as $r) {
            $ecart = $rapprochementService->calculerEcart($r);

            if ((int) round($ecart * 100) !== 0) {
                $dateFin = substr((string) $r->date_fin, 0, 10);
                $this->issues[] = "Rapprochement #{$r->id} ({$dateFin}, compte #{$r->compte_id}) : écart = {$ecart} €";
            }
        }
    }

    /**
     * CHECK 2 — Montant des remises = somme des transactions sources.
     */
    private function checkRemises(): void
    {
        $remises = DB::table('remises_bancaires')
            ->where('association_id', TenantContext::currentId())
            ->get();

        foreach ($remises as $remise) {
            $t4 = DB::table('transactions as t')
                ->join('transaction_lignes as tl', 'tl.transaction_id', '=', 't.id')
                ->join('comptes as c', 'c.id', '=', 'tl.compte_id')
                ->where('t.association_id', TenantContext::currentId())
                ->where('c.association_id', TenantContext::currentId())
                ->where('t.remise_id', $remise->id)
                ->whereNull('t.deleted_at')
                ->whereNull('tl.deleted_at')
                ->where('c.classe', 5)
                ->where('c.numero_pcg', 'like', '512_%')
                ->where('tl.debit', '>', 0)
                ->select('t.id', 't.montant_total')
                ->first();

            $sourcesTotal = (float) DB::table('transactions')
                ->where('remise_id', $remise->id)
                ->where('association_id', TenantContext::currentId())
                ->where('type', 'recette')
                ->whereNull('deleted_at')
                ->when($t4 !== null, fn ($query) => $query->where('id', '!=', $t4->id))
                ->sum('montant_total');

            if ($t4 !== null && (int) round(((float) $t4->montant_total - $sourcesTotal) * 100) !== 0) {
                $this->issues[] = "Remise #{$remise->id} : T4#{$t4->id} montant_total={$t4->montant_total} ≠ somme sources={$sourcesTotal}";
            }

            // Le montant affiché de la remise = somme des sources opérationnelles
            // Vérifier que les sources sont cohérentes (chaque TX.montant_total = sum lignes)
            $txSources = DB::table('transactions')
                ->where('remise_id', $remise->id)
                ->where('association_id', TenantContext::currentId())
                ->where('type', 'recette')
                ->whereNull('deleted_at')
                ->when($t4 !== null, fn ($query) => $query->where('id', '!=', $t4->id))
                ->get(['id', 'montant_total']);

            foreach ($txSources as $tx) {
                $sumLignes = (float) TransactionLigne::query()
                    ->where('transaction_id', $tx->id)
                    ->ventilation()
                    ->sum('montant');

                $diff = (int) round(((float) $tx->montant_total - $sumLignes) * 100);
                if ($diff !== 0) {
                    $this->issues[] = "Remise #{$remise->id} : TX#{$tx->id} montant_total={$tx->montant_total} ≠ sum(lignes)={$sumLignes}";
                }
            }
        }
    }

    /**
     * CHECK 3 — Chaque transaction : montant_total = net débit/crédit des ventilations.
     */
    private function checkTransactionTotals(): void
    {
        $shouldFix = (bool) $this->option('fix');

        // Requête agrégée : toutes les TX où montant_total ≠ net débit/crédit des
        // ventilations. On mesure sur debit/credit — la vérité en partie double —
        // et non sur `transaction_lignes.montant` : les OD (dotations et extournes
        // de provisions, compensations) posent montant = 0 sur leurs lignes, ce qui
        // faisait sonner ce contrôle sur des écritures parfaitement équilibrées.
        // Sens : une charge (classe 6) est au débit, un produit (classe 7) au
        // crédit ; les miroirs d'extourne inversent les deux et portent un
        // montant_total négatif, que cette convention retrouve telle quelle.
        $divergences = DB::select('
            SELECT t.id, t.montant_total, COALESCE(s.sum_lignes, 0) as sum_lignes
            FROM transactions t
            JOIN (
                SELECT tl.transaction_id,
                       SUM(CASE WHEN c.classe = 6
                                THEN tl.debit - tl.credit
                                ELSE tl.credit - tl.debit END) as sum_lignes
                FROM transaction_lignes tl
                JOIN comptes c ON c.id = tl.compte_id
                WHERE c.association_id = ?
                  AND c.classe IN (6, 7)
                  AND tl.deleted_at IS NULL
                GROUP BY tl.transaction_id
            ) s ON s.transaction_id = t.id
            WHERE t.association_id = ?
              AND t.deleted_at IS NULL
              AND ROUND(t.montant_total * 100) != ROUND(s.sum_lignes * 100)
        ', [TenantContext::currentId(), TenantContext::currentId()]);

        foreach ($divergences as $d) {
            $this->issues[] = "TX#{$d->id} : montant_total={$d->montant_total} ≠ sum(lignes)={$d->sum_lignes}";

            if ($shouldFix) {
                DB::table('transactions')
                    ->where('id', $d->id)
                    ->where('association_id', TenantContext::currentId())
                    ->update([
                        'montant_total' => $d->sum_lignes,
                        'updated_at' => now(),
                    ]);
                $this->warn("  → TX#{$d->id} corrigée : {$d->montant_total} → {$d->sum_lignes}");
            }
        }
    }

    /**
     * CHECK 4 — Chaque adhésion liée à une TX : montant_facial = net débit/crédit de la TX.
     *
     * Même convention de mesure que CHECK 3 (débit/crédit, pas `lignes.montant`).
     */
    private function checkAdhesionMontants(): void
    {
        $divergences = DB::select('
            SELECT a.id as adhesion_id, a.montant_facial, a.transaction_id,
                   COALESCE(s.sum_lignes, 0) as sum_lignes
            FROM adhesions a
            JOIN transactions t ON t.id = a.transaction_id
            LEFT JOIN (
                SELECT tl.transaction_id,
                       SUM(CASE WHEN c.classe = 6
                                THEN tl.debit - tl.credit
                                ELSE tl.credit - tl.debit END) as sum_lignes
                FROM transaction_lignes tl
                JOIN comptes c ON c.id = tl.compte_id
                WHERE c.association_id = ?
                  AND c.classe IN (6, 7)
                  AND tl.deleted_at IS NULL
                GROUP BY tl.transaction_id
            ) s ON s.transaction_id = a.transaction_id
            WHERE a.association_id = ?
              AND t.association_id = ?
              AND a.deleted_at IS NULL
              AND a.transaction_id IS NOT NULL
              AND t.deleted_at IS NULL
              AND ROUND(a.montant_facial * 100) != ROUND(COALESCE(s.sum_lignes, 0) * 100)
        ', [TenantContext::currentId(), TenantContext::currentId(), TenantContext::currentId()]);

        foreach ($divergences as $d) {
            $this->issues[] = "Adhésion #{$d->adhesion_id} (TX#{$d->transaction_id}) : montant_facial={$d->montant_facial} ≠ sum(lignes)={$d->sum_lignes}";
        }
    }
}
