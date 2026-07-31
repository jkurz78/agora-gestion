<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Association;
use App\Services\ExerciceService;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commande artisan de smoke-test v5 (spec §16.6, sous-slice 1d).
 *
 * Pour chaque tenant :
 *   - Vérifie l'invariant d'équilibre (SUM debit = SUM credit) par transaction
 *
 * Volet Rapport (chantier G) — Diagnostic non-échappement PD :
 *   - Liste les transactions qui ont une ventilation 6/7 mais AUCUNE écriture PD
 *   - Classe par source (HelloAsso, wizard adhésion, NDF, saisie manuelle…)
 *   - Détail optionnel via --detail
 *
 * Exit code 0 : aucune Tx déséquilibrée, aucune Tx sans PD.
 * Exit code 1 : au moins une Tx déséquilibrée, ou des Tx sans PD.
 *
 * Signature : compta:smoke-test-v5 {--asso=* : IDs des associations (défaut : toutes)} {--detail}
 */
final class SmokeTestV5Command extends Command
{
    protected $signature = 'compta:smoke-test-v5
                            {--asso=* : IDs des associations à tester (défaut : toutes)}
                            {--detail : Affiche le détail des transactions sans PD}';

    protected $description = "Smoke-test v5 : invariant d'équilibre + diagnostic non-échappement PD.";

    public function __construct(
        private readonly ExerciceService $exerciceService,
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
        $showDetail = (bool) $this->option('detail');

        /** @var array<int, Collection<int, object>> $allSansPd */
        $allSansPd = [];

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                $annee = $this->exerciceService->current();

                $txDesEquilibrees = $this->smokeTestTenant($asso, $annee);

                // --- Chantier G : diagnostic non-échappement PD ---
                $txSansPd = $this->listerTransactionsSansPd($annee);
                $nbSansPd = $txSansPd->count();

                if ($nbSansPd > 0) {
                    $allSansPd[(int) $asso->id] = $txSansPd;
                }

                $failed = $txDesEquilibrees > 0 || $nbSansPd > 0;

                if ($failed) {
                    $hasFailures = true;
                }

                $rows[] = [
                    "#{$asso->id} {$asso->nom}",
                    (string) $txDesEquilibrees,
                    (string) $nbSansPd,
                ];
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        $this->table(['Association', 'Tx déséquilibrées', 'Tx sans PD'], $rows);

        // --- Résumé par source ---
        if ($allSansPd !== []) {
            $this->newLine();
            $this->warn('Diagnostic non-échappement PD :');

            foreach ($allSansPd as $assoId => $txSansPd) {
                $parSource = $txSansPd->groupBy('source');
                $this->line("  Association #{$assoId} — {$txSansPd->count()} transaction(s) sans PD :");

                foreach ($parSource as $source => $group) {
                    $montantTotal = $group->sum('montant_total');
                    $this->line("    [{$source}] {$group->count()} tx, total ".number_format($montantTotal, 2, ',', ' ').' €');
                }

                // Détail par transaction
                if ($showDetail) {
                    $this->newLine();
                    $detailRows = [];

                    foreach ($txSansPd as $tx) {
                        $detailRows[] = [
                            (string) $tx->id,
                            $tx->date,
                            $tx->type,
                            $tx->source,
                            $tx->raison,
                            $tx->libelle,
                            number_format((float) $tx->montant_total, 2, ',', ' ').' €',
                        ];
                    }

                    $this->table(
                        ['ID', 'Date', 'Type', 'Source', 'Raison', 'Libellé', 'Montant'],
                        $detailRows,
                    );
                }
            }
        }

        if ($hasFailures) {
            $this->error('Smoke-test ÉCHOUÉ : Tx déséquilibrées ou Tx sans PD détectées.');

            return self::FAILURE;
        }

        $this->info('Smoke-test OK : aucune divergence détectée.');

        return self::SUCCESS;
    }

    // =========================================================================
    // Smoke-test d'un tenant
    // =========================================================================

    private function smokeTestTenant(Association $asso, int $annee): int
    {
        $txDesEquilibrees = $this->compterTxDesEquilibrees($annee);

        Log::info('[PartieDouble][SmokeTestV5] Tenant testé', [
            'association_id' => (int) $asso->id,
            'annee' => $annee,
            'tx_desequilibrees' => $txDesEquilibrees,
        ]);

        return $txDesEquilibrees;
    }

    // =========================================================================
    // Chantier G — Diagnostic non-échappement PD
    // =========================================================================

    /**
     * Liste les transactions de l'exercice qui ont une ligne de ventilation
     * (compte de classe 6/7) mais AUCUNE écriture PD
     * (aucune ligne avec compte_id IS NOT NULL et (debit > 0 OR credit > 0)).
     *
     * Exclut les T2/T4, qui ne portent que des comptes techniques de classes 4/5.
     *
     * Retourne une collection d'objets avec id, date, type, libelle, montant_total,
     * source (HelloAsso / Adhésion / NDF / Saisie manuelle) et raison probable du skip.
     *
     * @return Collection<int, object>
     */
    private function listerTransactionsSansPd(int $annee): Collection
    {
        $dateDebut = "{$annee}-09-01";
        $dateFin = ($annee + 1).'-08-31';

        // Toutes les transactions de l'exercice qui ont au moins une ventilation 6/7…
        // Les transactions à 0 € sont exemptées par design (miroir de
        // TransactionConverter::convertir() : cotisation offerte par code promo
        // HelloAsso etc. — aucune écriture PD possible ni souhaitable).
        $txAvecVentilation = DB::table('transactions')
            ->join('transaction_lignes', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->join('comptes', 'comptes.id', '=', 'transaction_lignes.compte_id')
            ->where('transactions.association_id', (int) TenantContext::currentId())
            ->where('comptes.association_id', (int) TenantContext::currentId())
            ->whereBetween('transactions.date', [$dateDebut, $dateFin])
            ->where('transactions.montant_total', '!=', 0)
            ->whereNull('transaction_lignes.deleted_at')
            ->whereIn('comptes.classe', [6, 7])
            ->groupBy('transactions.id')
            ->pluck('transactions.id');

        if ($txAvecVentilation->isEmpty()) {
            return collect();
        }

        // …et qui n'ont AUCUNE ligne PD (compte_id non null, debit+credit > 0)
        $txAvecPd = DB::table('transaction_lignes')
            ->join('transactions', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->whereIn('transaction_lignes.transaction_id', $txAvecVentilation)
            ->where('transactions.association_id', (int) TenantContext::currentId())
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNotNull('transaction_lignes.compte_id')
            ->where(fn ($q) => $q->where('transaction_lignes.debit', '>', 0)->orWhere('transaction_lignes.credit', '>', 0))
            ->groupBy('transaction_lignes.transaction_id')
            ->pluck('transaction_lignes.transaction_id');

        $txSansPdIds = $txAvecVentilation->diff($txAvecPd);

        if ($txSansPdIds->isEmpty()) {
            return collect();
        }

        // Charger les détails pour le diagnostic
        $transactions = DB::table('transactions')
            ->whereIn('id', $txSansPdIds)
            ->where('association_id', (int) TenantContext::currentId())
            ->select('id', 'date', 'type', 'libelle', 'montant_total', 'tiers_id',
                'helloasso_order_id', 'journal', 'mode_paiement')
            ->get();

        // Identifier les transactions issues du wizard adhésion
        $txIdsAdhesion = DB::table('adhesions')
            ->whereIn('transaction_id', $txSansPdIds)
            ->where('association_id', (int) TenantContext::currentId())
            ->pluck('transaction_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Identifier les transactions issues de NDF
        $txIdsNdf = DB::table('notes_de_frais')
            ->whereIn('transaction_id', $txSansPdIds)
            ->where('association_id', (int) TenantContext::currentId())
            ->pluck('transaction_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $txIdsDonNdf = DB::table('notes_de_frais')
            ->whereIn('don_transaction_id', $txSansPdIds)
            ->where('association_id', (int) TenantContext::currentId())
            ->pluck('don_transaction_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $transactions->map(function (object $tx) use ($txIdsAdhesion, $txIdsNdf, $txIdsDonNdf): object {
            $tx->source = $this->classerSource($tx, $txIdsAdhesion, $txIdsNdf, $txIdsDonNdf);
            $tx->raison = $this->devinerRaison($tx);

            return $tx;
        });
    }

    /**
     * Classe la source d'une transaction sans PD.
     *
     * @param  int[]  $txIdsAdhesion
     * @param  int[]  $txIdsNdf
     * @param  int[]  $txIdsDonNdf
     */
    private function classerSource(object $tx, array $txIdsAdhesion, array $txIdsNdf, array $txIdsDonNdf): string
    {
        if ($tx->helloasso_order_id !== null) {
            return 'HelloAsso';
        }

        if (in_array((int) $tx->id, $txIdsAdhesion, true)) {
            return 'Adhésion (wizard)';
        }

        if (in_array((int) $tx->id, $txIdsNdf, true)) {
            return 'NDF (dépense)';
        }

        if (in_array((int) $tx->id, $txIdsDonNdf, true)) {
            return 'NDF (don abandon)';
        }

        return 'Saisie manuelle';
    }

    /**
     * Devine la raison probable du skip PD.
     */
    private function devinerRaison(object $tx): string
    {
        if ($tx->tiers_id === null) {
            return 'tiers_id null';
        }

        return 'ventilation sans débit/crédit';
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
            ->where('transactions.association_id', (int) TenantContext::currentId())
            ->whereBetween('transactions.date', [$dateDebut, $dateFin])
            ->whereNull('transaction_lignes.deleted_at')
            ->groupBy('transaction_lignes.transaction_id')
            ->havingRaw('ABS(SUM(transaction_lignes.debit) - SUM(transaction_lignes.credit)) > 0.01')
            ->selectRaw('COUNT(*) as nb')
            ->get();

        return $result->count();
    }
}
