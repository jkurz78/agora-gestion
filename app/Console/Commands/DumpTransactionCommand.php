<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Sens;
use App\Enums\TypeTransaction;
use App\Models\Association;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Commande artisan d'audit visuel d'une transaction en mode partie double.
 *
 * Affiche :
 *   - En-tête : identité de la transaction (type, mode, montant, date, tiers, compte, équilibre)
 *   - Tableau des lignes (PCG, intitulé, débit, crédit, sous-catégorie, code lettrage)
 *   - Lettrages actifs avec les paires de lignes appariées
 *   - Transactions liées (remise, facture, encaissement T1↔T2, extourne)
 *   - Sources consolidées si la transaction est une T4 de remise bancaire
 *
 * Signature : compta:dump-transaction {id} {--asso=}
 * Exit code  : 0 si OK, 1 si transaction introuvable.
 */
final class DumpTransactionCommand extends Command
{
    protected $signature = 'compta:dump-transaction
                            {id : ID de la transaction}
                            {--asso= : ID de l\'association (optionnel — autodétection si absent)}';

    protected $description = 'Dump détaillé d\'une transaction (audit cycle partie double).';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $assoOption = $this->option('asso');

        // --- Résolution de l'association ---
        $association = $this->resolveAssociation($id, $assoOption);

        if ($association === null) {
            $this->error("Transaction #{$id} introuvable (ou association introuvable).");

            return self::FAILURE;
        }

        // --- Boot tenant ---
        TenantContext::clear();
        TenantContext::boot($association);

        // --- Charger la transaction avec ses relations ---
        $tx = Transaction::with([
            'lignes.compte',
            'lignes.sousCategorie',
            'lignes.tiers',
            'lignes.operation',
            'tiers',
            'compte',
            'factures',
            'remise',
            'extourneeVers.extourne',
            'extournePour.origine',
        ])->find($id);

        if ($tx === null) {
            $this->error("Transaction #{$id} introuvable dans l'association #{$association->id}.");

            return self::FAILURE;
        }

        // --- Affichage ---
        $this->afficherEntete($tx, $association);
        $this->afficherLignes($tx);
        $this->afficherLettrages($tx);
        $this->afficherTransactionsLiees($tx);

        if ($this->estT4Remise($tx)) {
            $this->afficherSourcesConsolidees($tx);
        }

        Log::info('[PartieDouble][DumpTransaction] Dump effectué', [
            'transaction_id' => $id,
            'association_id' => (int) $association->id,
        ]);

        return self::SUCCESS;
    }

    // =========================================================================
    // Résolution association
    // =========================================================================

    private function resolveAssociation(int $txId, mixed $assoOption): ?Association
    {
        if ($assoOption !== null) {
            $assoId = (int) $assoOption;
            $association = Association::find($assoId);

            if ($association === null) {
                return null;
            }

            // Vérifier que la transaction appartient à cette association
            $exists = Transaction::withoutGlobalScopes()
                ->where('id', $txId)
                ->where('association_id', $assoId)
                ->exists();

            if (! $exists) {
                return null;
            }

            return $association;
        }

        // Autodétection : chercher sans scope global
        $txRaw = Transaction::withoutGlobalScopes()->find($txId);

        if ($txRaw === null) {
            return null;
        }

        return Association::find($txRaw->association_id);
    }

    // =========================================================================
    // Affichage en-tête
    // =========================================================================

    private function afficherEntete(Transaction $tx, Association $association): void
    {
        $separateur = str_repeat('═', 63);
        $this->line($separateur);

        $libelle = mb_strimwidth((string) $tx->libelle, 0, 50, '…');
        $this->line("  Transaction #{$tx->id} — {$tx->type->label()} « {$libelle} »");

        $mode = $tx->mode_paiement?->label() ?? '-';
        $montant = number_format((float) $tx->montant_total, 2, ',', ' ');
        $date = $tx->date?->format('d/m/Y') ?? '-';
        $this->line("  Mode: {$mode}  |  Montant: {$montant}€  |  Date: {$date}");

        $sens = $tx->type === TypeTransaction::Depense ? Sens::Depense : Sens::Recette;
        $statut = $tx->statut_reglement?->label($sens) ?? '-';
        $this->line("  Type: {$tx->type->value}  |  Statut: {$statut}");

        $tiersLabel = $tx->tiers ? $tx->tiers->displayName().' (#'.(int) $tx->tiers->id.')' : '-';
        $compteLabel = $tx->compte ? $tx->compte->nom.' (#'.(int) $tx->compte->id.')' : '-';
        $this->line("  Tiers: {$tiersLabel}  |  Compte bancaire: {$compteLabel}");

        $equilibree = $tx->equilibree ? '✓' : '✗';
        $this->line("  Équilibrée: {$equilibree}");

        $assoLabel = '#'.(int) $association->id.' '.$association->nom;
        $this->line("  Association: {$assoLabel}");

        $this->line($separateur);
        $this->line('');
    }

    // =========================================================================
    // Affichage tableau des lignes
    // =========================================================================

    private function afficherLignes(Transaction $tx): void
    {
        $lignes = $tx->lignes;
        $count = $lignes->count();

        $this->line("Lignes ({$count}):");

        $totalDebit = 0.0;
        $totalCredit = 0.0;

        $rows = [];
        foreach ($lignes as $ligne) {
            $pcg = $ligne->compte?->numero_pcg ?? '-';
            $intitule = mb_strimwidth($ligne->compte?->intitule ?? '-', 0, 16, '…');
            $debit = $ligne->debit !== null && (float) $ligne->debit > 0
                ? number_format((float) $ligne->debit, 2)
                : '';
            $credit = $ligne->credit !== null && (float) $ligne->credit > 0
                ? number_format((float) $ligne->credit, 2)
                : '';
            $sousCat = $ligne->sousCategorie
                ? mb_strimwidth($ligne->sousCategorie->nom, 0, 12, '…').' (#'.(int) $ligne->sous_categorie_id.')'
                : '-';
            $tiers = $ligne->tiers
                ? mb_strimwidth($ligne->tiers->displayName(), 0, 14, '…').' (#'.(int) $ligne->tiers_id.')'
                : '-';
            $operation = $ligne->operation
                ? mb_strimwidth($ligne->operation->nom, 0, 12, '…').' (#'.(int) $ligne->operation_id.')'
                : '-';
            $seance = $ligne->seance !== null ? (string) (int) $ligne->seance : '-';
            $lettrage = $ligne->lettrage_code
                ? mb_strimwidth($ligne->lettrage_code, 0, 12, '…')
                : '-';

            $totalDebit += (float) ($ligne->debit ?? 0);
            $totalCredit += (float) ($ligne->credit ?? 0);

            $rows[] = [
                '#'.(int) $ligne->id,
                $pcg,
                $intitule,
                $debit,
                $credit,
                $sousCat,
                $tiers,
                $operation,
                $seance,
                $lettrage,
            ];
        }

        // Ligne TOTAL
        $rows[] = [
            'TOTAL',
            '',
            '',
            number_format($totalDebit, 2),
            number_format($totalCredit, 2),
            '',
            '',
            '',
            '',
            '',
        ];

        $this->table(
            ['Ligne', 'PCG', 'Intitulé', 'Débit', 'Crédit', 'Sous-cat', 'Tiers', 'Opération', 'Séance', 'Lettrage'],
            $rows
        );

        $this->line('');
    }

    // =========================================================================
    // Affichage lettrages actifs
    // =========================================================================

    private function afficherLettrages(Transaction $tx): void
    {
        /** @var Collection<int, TransactionLigne> $lignesLettrées */
        $lignesLettrées = $tx->lignes->filter(fn (TransactionLigne $l) => $l->lettrage_code !== null);

        if ($lignesLettrées->isEmpty()) {
            $this->line('Lettrages actifs: aucun.');
            $this->line('');

            return;
        }

        // Grouper par (compte_id, lettrage_code) — le code est séquentiel par compte
        $groupes = $lignesLettrées
            ->map(fn (TransactionLigne $l) => ['compte_id' => (int) $l->compte_id, 'code' => $l->lettrage_code])
            ->unique(fn (array $g) => $g['compte_id'].':'.$g['code'])
            ->values();

        $totalGroupes = $groupes->count();
        $this->line("Lettrages actifs ({$totalGroupes}):");

        foreach ($groupes as $groupe) {
            $code = $groupe['code'];
            $compteId = $groupe['compte_id'];

            // Charger toutes les lignes portant ce code SUR LE MÊME COMPTE
            $toutesLignes = TransactionLigne::withoutGlobalScope(SoftDeletingScope::class)
                ->where('lettrage_code', $code)
                ->where('compte_id', $compteId)
                ->with('compte')
                ->get();

            $nbLignes = $toutesLignes->count();
            $txIds = $toutesLignes->pluck('transaction_id')->unique()->sort()->implode(', ');
            $labelTx = $nbLignes > 0 && $toutesLignes->pluck('transaction_id')->unique()->count() > 1
                ? "cross-transaction (Tx: {$txIds})"
                : 'intra-transaction';

            $codeAffiche = mb_strimwidth($code, 0, 20, '…');
            $this->line("  {$codeAffiche} — {$nbLignes} lignes appariées ({$labelTx})");

            foreach ($toutesLignes as $l) {
                $pcg = $l->compte?->numero_pcg ?? '?';
                $intitule = mb_strimwidth($l->compte?->intitule ?? '?', 0, 20, '…');
                $debitStr = (float) $l->debit > 0 ? 'D '.number_format((float) $l->debit, 2) : '';
                $creditStr = (float) $l->credit > 0 ? 'C '.number_format((float) $l->credit, 2) : '';
                $sens = $debitStr ?: $creditStr;
                $txRef = (int) $l->transaction_id !== (int) $tx->id
                    ? ' [Tx#'.(int) $l->transaction_id.']'
                    : '';
                $this->line("    L#{$l->id} {$pcg} {$intitule} {$sens}{$txRef}");
            }
        }

        $this->line('');
    }

    // =========================================================================
    // Affichage transactions liées
    // =========================================================================

    private function afficherTransactionsLiees(Transaction $tx): void
    {
        $liees = [];

        // --- Factures liées ---
        foreach ($tx->factures as $facture) {
            $num = $facture->numero ?? "#{$facture->id}";
            $statut = $facture->statut?->value ?? '-';
            $liees[] = "Liée à facture {$num} (statut: {$statut})";
        }

        // --- Remise bancaire : Tx source (T1) avec remise_id ---
        if ($tx->remise_id !== null) {
            $remise = $tx->remise;
            $remiseLabel = $remise ? "remise #{$remise->id} du ".$remise->date?->format('d/m/Y') : "remise #{$tx->remise_id}";
            $liees[] = "Fait partie de {$remiseLabel}";

            // Chercher la T4 correspondante : transaction dont les lignes 5112-C
            // sont lettrées avec les lignes 5112-D de cette T1
            $lignes5112T1 = $tx->lignes->filter(
                fn (TransactionLigne $l) => $l->lettrage_code !== null
                    && $l->compte !== null
                    && $l->compte->numero_pcg === '5112'
                    && (float) $l->debit > 0
            );

            foreach ($lignes5112T1 as $ligne) {
                $t4ligne = TransactionLigne::where('lettrage_code', $ligne->lettrage_code)
                    ->where('compte_id', (int) $ligne->compte_id)
                    ->where('transaction_id', '!=', $tx->id)
                    ->first();

                if ($t4ligne !== null) {
                    $liees[] = "  → T4 de remise : Tx #{$t4ligne->transaction_id}";
                    break;
                }
            }
        }

        // --- Encaissement : détecter T1↔T2 via lettrage 411 ---
        $lignes411 = $tx->lignes->filter(
            fn (TransactionLigne $l) => $l->lettrage_code !== null
                && $l->compte !== null
                && $l->compte->numero_pcg === '411'
        );

        foreach ($lignes411 as $ligne) {
            // Chercher la contrepartie dans une autre transaction (même compte)
            $contrepartie = TransactionLigne::where('lettrage_code', $ligne->lettrage_code)
                ->where('compte_id', (int) $ligne->compte_id)
                ->where('transaction_id', '!=', $tx->id)
                ->first();

            if ($contrepartie !== null) {
                $autreId = (int) $contrepartie->transaction_id;

                if ((float) $ligne->debit > 0) {
                    // Cette Tx a 411D → c'est une T1 créance, T2 est $autreId
                    $liees[] = "T1 créance encaissée par Tx #{$autreId}";
                } else {
                    // Cette Tx a 411C → c'est une T2, T1 est $autreId
                    $liees[] = "T2 d'encaissement de Tx #{$autreId} (créance)";
                }
                break;
            }
        }

        // --- Extourne ---
        if ($tx->extourneeVers !== null) {
            $miroirId = (int) $tx->extourneeVers->transaction_extourne_id;
            $liees[] = "Origine extournée par Tx #{$miroirId}";
        }

        if ($tx->extournePour !== null) {
            $origineId = (int) $tx->extournePour->transaction_origine_id;
            $liees[] = "Miroir extourne de Tx #{$origineId}";
        }

        if ($liees === []) {
            $this->line('Transactions liées:');
            $this->line('  Aucune (pas de remise, pas d\'extourne, pas de facture).');
        } else {
            $this->line('Transactions liées:');
            foreach ($liees as $item) {
                $this->line("  {$item}");
            }
        }

        $this->line('');
    }

    // =========================================================================
    // Détection T4 de remise
    // =========================================================================

    /**
     * Détecte si la transaction est une T4 de remise bancaire.
     *
     * Critères : equilibree=true, remise_id=null, ET possède des lignes 5112/530-C
     * lettrées dont les contreparties sont dans d'autres transactions qui ont un remise_id.
     */
    private function estT4Remise(Transaction $tx): bool
    {
        // Si la Tx a un remise_id, c'est une T1 source, pas une T4
        if ($tx->remise_id !== null) {
            return false;
        }

        // Chercher les lignes 5112/530 crédit lettrées
        $lignesPortageCredit = $tx->lignes->filter(
            fn (TransactionLigne $l) => $l->lettrage_code !== null
                && $l->compte !== null
                && in_array($l->compte->numero_pcg, ['5112', '530'], true)
                && (float) $l->credit > 0
        );

        if ($lignesPortageCredit->isEmpty()) {
            return false;
        }

        // Vérifier qu'au moins une contrepartie est dans une T1 avec remise_id
        foreach ($lignesPortageCredit as $ligne) {
            $contrepartie = TransactionLigne::where('lettrage_code', $ligne->lettrage_code)
                ->where('compte_id', (int) $ligne->compte_id)
                ->where('transaction_id', '!=', $tx->id)
                ->first();

            if ($contrepartie !== null) {
                $txSource = Transaction::withoutGlobalScopes()
                    ->select(['id', 'remise_id'])
                    ->find($contrepartie->transaction_id);

                if ($txSource !== null && $txSource->remise_id !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    // =========================================================================
    // Affichage sources consolidées (T4 de remise)
    // =========================================================================

    private function afficherSourcesConsolidees(Transaction $tx): void
    {
        // Collecter les T1 sources via les contreparties lettrées sur 5112/530
        $lignesPortageCredit = $tx->lignes->filter(
            fn (TransactionLigne $l) => $l->lettrage_code !== null
                && $l->compte !== null
                && in_array($l->compte->numero_pcg, ['5112', '530'], true)
                && (float) $l->credit > 0
        );

        $txSources = collect();

        foreach ($lignesPortageCredit as $ligne) {
            $contrepartie = TransactionLigne::where('lettrage_code', $ligne->lettrage_code)
                ->where('compte_id', (int) $ligne->compte_id)
                ->where('transaction_id', '!=', $tx->id)
                ->first();

            if ($contrepartie !== null) {
                $txSource = Transaction::withoutGlobalScopes()->find($contrepartie->transaction_id);

                if ($txSource !== null && $txSource->remise_id !== null) {
                    $txSources->put((int) $txSource->id, $txSource);
                }
            }
        }

        if ($txSources->isEmpty()) {
            return;
        }

        // Détecter le mode (chèque / espèces) depuis la remise de la 1ère source
        $premiereSource = $txSources->first();
        $remise = $premiereSource?->remise;
        $modeLabel = $remise?->mode_paiement?->label() ?? 'paiements';
        $count = $txSources->count();

        $this->line("Sources consolidées ({$count} {$modeLabel}):");

        foreach ($txSources->sortKeys() as $txSource) {
            $montant = number_format((float) $txSource->montant_total, 2, ',', ' ');
            $libelle = mb_strimwidth((string) $txSource->libelle, 0, 40, '…');
            $this->line("  Tx#{$txSource->id} — {$libelle} {$montant}€");
        }

        $this->line('');
    }
}
