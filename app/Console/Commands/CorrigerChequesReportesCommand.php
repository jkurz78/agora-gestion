<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\StatutRapprochement;
use App\Models\Association;
use App\Models\Compte;
use App\Models\RapprochementBancaire;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Correctif OneShot : chèques déjà encaissés avant AgoraGestion (reprise d'historique).
 *
 * Contexte (cutover v5) :
 *   Lors de la reprise comptable initiale, certains chèques étaient déjà physiquement
 *   encaissés sur le compte en banque avant l'existence d'AgoraGestion. Ils ont été saisis
 *   puis pointés directement sur le rapprochement bancaire, sans jamais passer par la
 *   fonction « Remise bancaire » (il n'y avait pas de remise à créer — l'argent était déjà
 *   sur le compte).
 *
 *   Le backfill partie double route tout chèque reçu via le compte de portage 5112
 *   (« Chèques à encaisser ») puis attend une remise (T4) pour le déplacer vers le 512X.
 *   Pour ces chèques de reprise, cette remise n'existe pas → ils restent bloqués en 5112,
 *   ce qui surévalue « Chèques à encaisser » et désaligne le solde de pointage PD du legacy.
 *
 * Correctif :
 *   Pour chaque ligne 5112 en débit, NON lettrée, dont la transaction est pointée sur un
 *   rapprochement VERROUILLÉ (= preuve qu'elle est réellement sur le relevé bancaire),
 *   bascule le compte de la ligne 5112 → 512X de la banque concernée. Le chèque est alors
 *   booké directement sur la banque, fidèle à la réalité « déjà encaissé ».
 *
 * Sûreté :
 *   - Ne touche que les lignes 5112 NON lettrées (un chèque réellement remis via T4 a sa
 *     ligne 5112 lettrée → exclu).
 *   - Ne touche que les transactions pointées sur un rappro verrouillé (un chèque encore en
 *     attente d'encaissement n'est pas pointé → reste légitimement en 5112).
 *   - Débit/crédit inchangés (seul compte_id change) → équilibre et flag equilibree intacts.
 *   - Idempotent : après bascule, la ligne n'est plus en 5112 → re-run = 0 correction.
 *
 * OneShot : ce cas n'existe que sur la reprise d'historique initiale. À jouer une fois lors
 * du cutover, juste avant compta:smoke-test-v5.
 *
 * Signature : compta:corriger-cheques-reportes {--dry-run} {--asso=}
 */
final class CorrigerChequesReportesCommand extends Command
{
    protected $signature = 'compta:corriger-cheques-reportes
                            {--dry-run : Audit seulement, aucune écriture en base}
                            {--asso= : Limiter à une association (ID)}';

    protected $description = 'Correctif OneShot : bascule 5112 → 512X les chèques de reprise déjà encaissés avant AgoraGestion.';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $assoOption = $this->option('asso');
        $associations = $assoOption !== null
            ? Association::query()->whereKey((int) $assoOption)->get()
            : Association::query()->get();

        if ($associations->isEmpty()) {
            $this->warn('Aucune association à traiter.');

            return self::SUCCESS;
        }

        $previousTenant = TenantContext::current();
        $totalCorrige = 0;

        try {
            foreach ($associations as $asso) {
                TenantContext::clear();
                TenantContext::boot($asso);

                $totalCorrige += $this->corrigerTenant($asso, $isDryRun);
            }
        } finally {
            TenantContext::clear();
            if ($previousTenant !== null) {
                TenantContext::boot($previousTenant);
            }
        }

        $this->line('');
        if ($isDryRun) {
            $this->warn("Dry-run terminé : {$totalCorrige} ligne(s) à corriger. Relancer sans --dry-run pour appliquer.");
        } else {
            $this->info("Correctif terminé : {$totalCorrige} ligne(s) basculée(s) 5112 → 512X.");
        }

        return self::SUCCESS;
    }

    /**
     * Corrige les chèques de reprise d'un tenant. Retourne le nombre de lignes traitées.
     */
    private function corrigerTenant(Association $asso, bool $isDryRun): int
    {
        $assoId = (int) $asso->id;

        $compte5112 = Compte::ofNumero('5112');
        if ($compte5112 === null) {
            // Tenant sans schéma partie double — rien à corriger.
            return 0;
        }

        $rapproVerrouilles = RapprochementBancaire::where('statut', StatutRapprochement::Verrouille)
            ->pluck('id')
            ->all();

        if (empty($rapproVerrouilles)) {
            return 0;
        }

        // Lignes candidates : 5112 en débit, NON lettrées, transaction pointée sur un rappro verrouillé.
        $candidates = TransactionLigne::query()
            ->where('transaction_lignes.compte_id', $compte5112->id)
            ->where('transaction_lignes.debit', '>', 0)
            ->whereNull('transaction_lignes.lettrage_code')
            ->whereNull('transaction_lignes.deleted_at')
            ->whereHas('transaction', function ($q) use ($rapproVerrouilles) {
                $q->whereIn('rapprochement_id', $rapproVerrouilles);
            })
            ->with('transaction')
            ->get();

        if ($candidates->isEmpty()) {
            $this->line("Association #{$assoId} {$asso->nom} : aucun chèque de reprise à corriger.");

            return 0;
        }

        // Résolution du 512X cible par banque (compte_bancaire_id de la transaction).
        $rows = [];
        $aBasculer = [];
        $orphelins = 0;

        foreach ($candidates as $ligne) {
            $tx = $ligne->transaction;
            $compte512X = Compte::where('compte_bancaire_id', $tx->compte_id)->bancaires()->first();

            if ($compte512X === null) {
                $orphelins++;
                $this->warn("  Tx #{$tx->id} : 512X introuvable pour CompteBancaire #{$tx->compte_id} — ligne ignorée.");

                continue;
            }

            $rows[] = [
                "#{$tx->id}",
                number_format((float) $ligne->debit, 2).'€',
                $compte5112->numero_pcg.' → '.$compte512X->numero_pcg,
            ];
            $aBasculer[] = ['ligne_id' => (int) $ligne->id, 'compte_id' => (int) $compte512X->id, 'tx_id' => (int) $tx->id];
        }

        $this->line('');
        $this->info("Association #{$assoId} {$asso->nom} — chèques de reprise (5112 → 512X)");
        $this->table(['Transaction', 'Montant', 'Bascule'], $rows);
        $total = number_format((float) $candidates->sum('debit'), 2);
        $this->line("  {$candidates->count()} ligne(s), total ".$total.'€'.($orphelins > 0 ? " ({$orphelins} ignorée(s), 512X introuvable)" : ''));

        if ($isDryRun || empty($aBasculer)) {
            return count($aBasculer);
        }

        DB::transaction(function () use ($aBasculer, $assoId): void {
            foreach ($aBasculer as $item) {
                TransactionLigne::whereKey($item['ligne_id'])->update(['compte_id' => $item['compte_id']]);

                Log::info('[PartieDouble][CorrectifChequesReportes] Ligne basculée 5112 → 512X', [
                    'association_id' => $assoId,
                    'transaction_ligne_id' => $item['ligne_id'],
                    'transaction_id' => $item['tx_id'],
                    'compte_512X_id' => $item['compte_id'],
                ]);
            }
        });

        return count($aBasculer);
    }
}
