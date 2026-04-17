<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TypeOperation;
use App\Models\User;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Benchmark artisan : seed N tenants × M transactions puis mesure 5 requêtes lourdes.
 *
 * Approche choisie : C — Minimal (requêtes DB directes)
 * Rationale : le dispatch HTTP via `$this->laravel['router']` ne dispose pas de session ni
 * d'auth dans le contexte d'une commande artisan (redirects 302 attendus). Plutôt que de
 * simuler un navigateur, on mesure les requêtes Eloquent/DB qui constituent l'essentiel du
 * temps des écrans lourds : count(), paginate(), filtre association_id. Le résultat est
 * reproductible, independant de Blade/Livewire, et indique clairement si les index composites
 * ont un impact sur le plan d'exécution.
 *
 * Usage :
 *   php artisan tenant:benchmark --tenants=10 --transactions=1000
 *   php artisan tenant:benchmark --tenants=3 --transactions=50
 */
final class TenantBenchmark extends Command
{
    protected $signature = 'tenant:benchmark {--tenants=10} {--transactions=1000}';

    protected $description = 'Seed N tenants × M transactions puis mesure 5 requêtes lourdes par tenant cible.';

    public function handle(): int
    {
        $tenantsCount = (int) $this->option('tenants');
        $transactionsCount = (int) $this->option('transactions');

        $this->info("Seed {$tenantsCount} tenants × {$transactionsCount} transactions…");

        // ── Seed ─────────────────────────────────────────────────────────────────
        $tenants = Association::factory()->count($tenantsCount)->create();

        // Pre-create a shared user to avoid Faker unique-email exhaustion when seeding
        // large volumes (1000 × N transactions each needing saisi_par).
        $sharedUser = User::factory()->create();

        foreach ($tenants as $tenant) {
            TenantContext::boot($tenant);

            // Pre-create shared resources per tenant to avoid Faker unique-pool exhaustion.
            $compte = CompteBancaire::factory()->create(['association_id' => $tenant->id]);
            $typeOp = TypeOperation::factory()->create(['association_id' => $tenant->id]);

            Transaction::factory()
                ->count($transactionsCount)
                ->create([
                    'association_id' => $tenant->id,
                    'saisi_par' => $sharedUser->id,
                    'compte_id' => $compte->id,
                ]);

            Operation::factory()->count((int) ceil($transactionsCount / 10))->create([
                'association_id' => $tenant->id,
                'type_operation_id' => $typeOp->id,
            ]);
            Tiers::factory()->count((int) ceil($transactionsCount / 5))->create(['association_id' => $tenant->id]);
            // Facture has no factory — factures count in the benchmark will be 0 (still exercises the index)
            TenantContext::clear();
        }

        $target = $tenants->first();
        $targetId = $target->id;

        $this->info("Benchmark sur tenant #{$targetId} ({$target->name})…");

        TenantContext::boot($target);

        // ── Mesures ──────────────────────────────────────────────────────────────
        $screens = [
            'Dashboard' => fn () => Transaction::where('association_id', $targetId)->count(),
            'Operations list' => fn () => Operation::where('association_id', $targetId)->orderBy('date_debut', 'desc')->get()->count(),
            'Tiers 360' => fn () => Tiers::where('association_id', $targetId)->orderBy('nom')->paginate(50)->total(),
            'Factures' => fn () => DB::table('factures')->where('association_id', $targetId)->where('statut', 'brouillon')->count(),
            'Rapports CERFA' => fn () => Transaction::where('association_id', $targetId)->whereYear('date', date('Y'))->sum('montant_total'),
        ];

        $rows = [];

        foreach ($screens as $label => $fn) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $start = hrtime(true);
            $fn();
            $elapsed = (hrtime(true) - $start) / 1e6;

            $log = DB::getQueryLog();
            DB::disableQueryLog();

            $queryCount = count($log);
            $queryMs = array_sum(array_column($log, 'time'));

            $rows[] = [
                $label,
                $queryCount,
                round($elapsed, 1).' ms',
                round($queryMs, 1).' ms',
            ];
        }

        TenantContext::clear();

        $this->table(
            ['Écran', 'Requêtes', 'Durée totale', 'Durée DB'],
            $rows
        );

        $this->info('Benchmark terminé. Exit 0.');

        return self::SUCCESS;
    }
}
