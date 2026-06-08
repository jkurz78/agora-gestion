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
use Illuminate\Support\Collection;
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
 * Volet Rapport (chantier G) — Diagnostic non-échappement PD :
 *   - Liste les transactions qui ont des lignes legacy mais AUCUNE écriture PD
 *   - Classe par source (HelloAsso, wizard adhésion, NDF, saisie manuelle…)
 *   - Détail optionnel via --detail
 *
 * Exit code 0 : aucune divergence > 0,01€, aucune Tx déséquilibrée.
 * Exit code 1 : au moins une divergence, une Tx déséquilibrée, ou des Tx sans PD.
 *
 * Signature : compta:smoke-test-v5 {--asso=* : IDs des associations (défaut : toutes)} {--detail}
 */
final class SmokeTestV5Command extends Command
{
    protected $signature = 'compta:smoke-test-v5
                            {--asso=* : IDs des associations à tester (défaut : toutes)}
                            {--detail : Affiche le détail des transactions sans PD}';

    protected $description = 'Smoke-test v5 : compare legacy vs partie double + diagnostic non-échappement PD.';

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
        $showDetail = (bool) $this->option('detail');

        /** @var array<int, Collection<int, object>> $allSansPd */
        $allSansPd = [];

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                $annee = $this->exerciceService->current();

                [$crDelta, $rapproDelta, $txDesEquilibrees] = $this->smokeTestTenant($asso, $annee);

                // --- Chantier G : diagnostic non-échappement PD ---
                $txSansPd = $this->listerTransactionsSansPd($annee);
                $nbSansPd = $txSansPd->count();

                if ($nbSansPd > 0) {
                    $allSansPd[(int) $asso->id] = $txSansPd;
                }

                $failed = abs($crDelta) > 0.01 || abs($rapproDelta) > 0.01 || $txDesEquilibrees > 0 || $nbSansPd > 0;

                if ($failed) {
                    $hasFailures = true;
                }

                $rows[] = [
                    "#{$asso->id} {$asso->nom}",
                    number_format(abs($crDelta), 2).'€',
                    number_format(abs($rapproDelta), 2).'€',
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

        $this->table(['Association', 'CR Δ€', 'Rappro Δ€', 'Tx déséquilibrées', 'Tx sans PD'], $rows);

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
            $this->error('Smoke-test ÉCHOUÉ : divergences, Tx déséquilibrées ou Tx sans PD détectées.');

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
    // Chantier G — Diagnostic non-échappement PD
    // =========================================================================

    /**
     * Liste les transactions de l'exercice qui ont des lignes legacy
     * (sous_categorie_id IS NOT NULL) mais AUCUNE écriture PD
     * (aucune ligne avec compte_id IS NOT NULL et (debit > 0 OR credit > 0)).
     *
     * Exclut les T2/T4 (journal=banque, pas de sous_categorie_id) — elles n'ont
     * pas de lignes legacy par construction.
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

        // Toutes les transactions de l'exercice qui ont au moins une ligne legacy…
        $txAvecLegacy = DB::table('transactions')
            ->join('transaction_lignes', 'transactions.id', '=', 'transaction_lignes.transaction_id')
            ->where('transactions.association_id', (int) TenantContext::currentId())
            ->whereBetween('transactions.date', [$dateDebut, $dateFin])
            ->whereNull('transaction_lignes.deleted_at')
            ->whereNotNull('transaction_lignes.sous_categorie_id')
            ->groupBy('transactions.id')
            ->pluck('transactions.id');

        if ($txAvecLegacy->isEmpty()) {
            return collect();
        }

        // …et qui n'ont AUCUNE ligne PD (compte_id non null, debit+credit > 0)
        $txAvecPd = DB::table('transaction_lignes')
            ->whereIn('transaction_id', $txAvecLegacy)
            ->whereNull('deleted_at')
            ->whereNotNull('compte_id')
            ->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))
            ->groupBy('transaction_id')
            ->pluck('transaction_id');

        $txSansPdIds = $txAvecLegacy->diff($txAvecPd);

        if ($txSansPdIds->isEmpty()) {
            return collect();
        }

        // Charger les détails pour le diagnostic
        $transactions = DB::table('transactions')
            ->whereIn('id', $txSansPdIds)
            ->select('id', 'date', 'type', 'libelle', 'montant_total', 'tiers_id',
                'helloasso_order_id', 'journal', 'mode_paiement')
            ->get();

        // Identifier les transactions issues du wizard adhésion
        $txIdsAdhesion = DB::table('adhesions')
            ->whereIn('transaction_id', $txSansPdIds)
            ->pluck('transaction_id')
            ->all();

        // Identifier les transactions issues de NDF
        $txIdsNdf = DB::table('notes_de_frais')
            ->whereIn('transaction_id', $txSansPdIds)
            ->pluck('transaction_id')
            ->all();

        $txIdsDonNdf = DB::table('notes_de_frais')
            ->whereIn('don_transaction_id', $txSansPdIds)
            ->pluck('don_transaction_id')
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

        // Vérifier si une ligne a sous_categorie_id null (skip total)
        $ligneSansSousCat = DB::table('transaction_lignes')
            ->where('transaction_id', (int) $tx->id)
            ->whereNull('deleted_at')
            ->whereNull('sous_categorie_id')
            ->exists();

        if ($ligneSansSousCat) {
            return 'ligne sans sous-catégorie';
        }

        // Vérifier si un usage comptable manque (code_cerfa introuvable → compte null)
        $ligneSansCompte = DB::table('transaction_lignes')
            ->where('transaction_id', (int) $tx->id)
            ->whereNull('deleted_at')
            ->whereNotNull('sous_categorie_id')
            ->whereNull('compte_id')
            ->exists();

        if ($ligneSansCompte) {
            return 'usage comptable non configuré';
        }

        return 'bypass TransactionService';
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
