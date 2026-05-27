<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\RapprochementBancaire;
use App\Services\ExerciceService;
use App\Services\Rapports\CompteResultatBuilder;
use App\Services\RapprochementBancaireService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande artisan de smoke-test v5 (spec §16.6, sous-slice 1d).
 *
 * Pour chaque tenant :
 *   - Compare compte de résultat legacy vs PD (tolérance 0,01€)
 *   - Compare solde pointage des rapprochements verrouillés legacy vs PD
 *   - Vérifie l'invariant d'équilibre (SUM debit = SUM credit) par transaction
 *
 * Exit code 0 : aucune divergence > 0,01€, aucune Tx déséquilibrée.
 * Exit code 1 : au moins une divergence ou une Tx déséquilibrée.
 *
 * Signature : compta:smoke-test-v5 {--asso=* : IDs des associations (défaut : toutes)}
 */
final class SmokeTestV5Command extends Command
{
    protected $signature = 'compta:smoke-test-v5
                            {--asso=* : IDs des associations à tester (défaut : toutes)}';

    protected $description = 'Smoke-test v5 : compare legacy vs partie double sur chaque tenant.';

    public function __construct(
        private readonly ExerciceService $exerciceService,
        private readonly CompteResultatBuilder $crBuilder,
        private readonly RapprochementBancaireService $rapproService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $assoIds = $this->option('asso');
        $associations = ($assoIds !== [])
            ? Association::query()->whereIn('id', array_map('intval', (array) $assoIds))->get()
            : Association::query()->get();

        if ($associations->isEmpty()) {
            $this->warn('Aucune association à tester.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::current();
        $hasFailures = false;
        $rows = [];

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                $annee = $this->exerciceService->current();

                [$crDelta, $rapproDelta, $txDesEquilibrees] = $this->smokeTestTenant($asso, $annee);

                $failed = abs($crDelta) > 0.01 || abs($rapproDelta) > 0.01 || $txDesEquilibrees > 0;

                if ($failed) {
                    $hasFailures = true;
                }

                $rows[] = [
                    "#{$asso->id} {$asso->nom}",
                    number_format(abs($crDelta), 2).'€',
                    number_format(abs($rapproDelta), 2).'€',
                    (string) $txDesEquilibrees,
                ];
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        $this->table(['Association', 'CR Δ€', 'Rappro Δ€', 'Tx déséquilibrées'], $rows);

        if ($hasFailures) {
            $this->error('Smoke-test ÉCHOUÉ : divergences ou Tx déséquilibrées détectées.');

            return self::FAILURE;
        }

        $this->info('Smoke-test OK : aucune divergence détectée.');

        return self::SUCCESS;
    }

    // =========================================================================
    // Smoke-test d'un tenant
    // =========================================================================

    /**
     * @return array{float, float, int} [crDelta, rapproDelta, nbTxDesEquilibrees]
     */
    private function smokeTestTenant(Association $asso, int $annee): array
    {
        $crDelta = $this->comparerCompteResultat($annee);
        $rapproDelta = $this->comparerRapprochements($asso, $annee);
        $txDesEquilibrees = $this->compterTxDesEquilibrees($annee);

        Log::info('[PartieDouble][SmokeTestV5] Tenant testé', [
            'association_id' => (int) $asso->id,
            'annee' => $annee,
            'cr_delta' => $crDelta,
            'rappro_delta' => $rapproDelta,
            'tx_desequilibrees' => $txDesEquilibrees,
        ]);

        return [$crDelta, $rapproDelta, $txDesEquilibrees];
    }

    // =========================================================================
    // Comparaison compte de résultat
    // =========================================================================

    private function comparerCompteResultat(int $annee): float
    {
        // Sauvegarde du flag courant
        $currentFlag = config('compta.use_partie_double');

        try {
            // Mode legacy
            Config::set('compta.use_partie_double', false);
            $crLegacy = $this->crBuilder->compteDeResultat($annee);
            $totalLegacy = $this->sumCrTotals($crLegacy);

            // Mode PD
            Config::set('compta.use_partie_double', true);
            $crPd = $this->crBuilder->compteDeResultat($annee);
            $totalPd = $this->sumCrTotals($crPd);

            return round(abs($totalPd - $totalLegacy), 2);
        } finally {
            Config::set('compta.use_partie_double', $currentFlag);
        }
    }

    /**
     * Somme les montants N de toutes les lignes du compte de résultat.
     *
     * @param  array{charges: list<array>, produits: list<array>}  $cr
     */
    private function sumCrTotals(array $cr): float
    {
        $total = 0.0;
        foreach (['charges', 'produits'] as $section) {
            foreach ($cr[$section] as $row) {
                $total += (float) ($row['montant_n'] ?? $row['montant'] ?? 0);
                if (isset($row['children'])) {
                    foreach ($row['children'] as $child) {
                        $total += (float) ($child['montant_n'] ?? $child['montant'] ?? 0);
                    }
                }
            }
        }

        return $total;
    }

    // =========================================================================
    // Comparaison rapprochements verrouillés
    // =========================================================================

    private function comparerRapprochements(Association $asso, int $annee): float
    {
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        $rapprochements = RapprochementBancaire::where('statut', StatutRapprochement::Verrouille)
            ->whereBetween('date_fin', [$dateDebut, $dateFin])
            ->get();

        if ($rapprochements->isEmpty()) {
            return 0.0;
        }

        $currentFlag = config('compta.use_partie_double');
        $deltaTotal = 0.0;

        try {
            foreach ($rapprochements as $rappro) {
                Config::set('compta.use_partie_double', false);
                $soldeLegacy = $this->rapproService->calculerSoldePointage($rappro);

                Config::set('compta.use_partie_double', true);
                $soldePd = $this->rapproService->calculerSoldePointage($rappro);

                $deltaTotal += abs($soldePd - $soldeLegacy);
            }
        } finally {
            Config::set('compta.use_partie_double', $currentFlag);
        }

        return round($deltaTotal, 2);
    }

    // =========================================================================
    // Invariant équilibre
    // =========================================================================

    private function compterTxDesEquilibrees(int $annee): int
    {
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        // Pour chaque transaction de l'exercice, vérifier SUM(debit) = SUM(credit)
        // sur les lignes non supprimées.
        $result = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->whereBetween('transactions.date', [$dateDebut, $dateFin])
            ->whereNull('transaction_lignes.deleted_at')
            ->groupBy('transaction_lignes.transaction_id')
            ->havingRaw('ABS(SUM(transaction_lignes.debit) - SUM(transaction_lignes.credit)) > 0.01')
            ->selectRaw('COUNT(*) as nb')
            ->get();

        return $result->count();
    }
}
