<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de conversion d'une transaction legacy vers le modèle partie double.
 *
 * Extrait au Step 33 (testabilité unitaire + rule-of-three anticipée).
 *
 * Stratégie :
 *   1. Charger les lignes de ventilation legacy (celles avec sous_categorie_id non null).
 *   2. Résoudre les comptes via CompteVentilationResolver (sous_categorie → Compte 6x/7x).
 *   3. Enrichir les lignes legacy avec compte_id + debit/credit (mise à jour in-place).
 *   4. Appeler EcritureGenerator::pour*() avec existingTransaction pour créer les lignes
 *      PD-only (411/401, portage, lettrage).
 *   5. Marquer la transaction equilibree=TRUE (via l'observer XOR — déclenché par save).
 *
 * Skip silencieux si :
 *   - tiers_id null (transactions sans tiers — OD-like)
 *   - sous_categorie_id null sur une ligne (ligne sans catégorie)
 *   - CompteVentilationResolver retourne null (SC sans code_cerfa ou compte introuvable)
 *   - CompteTresorerieResolver retourne null (compte bancaire introuvable)
 *
 * Le caller (BackfillPartieDoubleCommand) enveloppe chaque conversion dans DB::transaction.
 *
 * Note dette Vague 3b (3ème caller autoDelettrerLignes) : BackfillPartieDoubleCommand est
 * le 3ème endroit qui appelle une logique de pre-conversion similaire à TransactionExtourneService.
 * Extraction d'un helper `autoDelettrerLignes()` mutualisé à planifier en Vague 3b.
 */
final class TransactionConverter
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
    ) {}

    /**
     * Convertit une transaction legacy vers le modèle partie double.
     *
     * Idempotent : si equilibree=TRUE, ne fait rien (guard côté caller).
     * Cette méthode assume que equilibree=FALSE ET qu'on est dans un DB::transaction.
     *
     * @throws \InvalidArgumentException Si les invariants ne sont pas respectés.
     * @throws \RuntimeException Si la conversion échoue (rollback côté caller).
     */
    public function convertir(Transaction $tx): bool
    {
        // Guard : tiers_id null → skip (OD-like, pas de lettrage)
        if ($tx->tiers_id === null) {
            Log::info('[Backfill] Skip : tiers_id null', ['transaction_id' => $tx->id]);

            return false;
        }

        /** @var Tiers $tiers */
        $tiers = Tiers::findOrFail($tx->tiers_id);

        // Charger les lignes legacy (ventilations : sous_categorie_id non null)
        $lignesLegacy = TransactionLigne::where('transaction_id', $tx->id)
            ->whereNotNull('sous_categorie_id')
            ->whereNull('deleted_at')
            ->get();

        if ($lignesLegacy->isEmpty()) {
            Log::info('[Backfill] Skip : aucune ligne legacy avec sous_categorie_id', ['transaction_id' => $tx->id]);

            return false;
        }

        // Résolution des ventilations (sous_categorie → Compte 6x/7x)
        $classeAttendue = $tx->type === TypeTransaction::Recette ? 7 : 6;
        $ventilations = [];
        $skipDoubleEcriture = false;

        foreach ($lignesLegacy as $ligne) {
            $compte = CompteVentilationResolver::resoudre(
                sousCategorieId: (int) $ligne->sous_categorie_id,
                classeAttendue: $classeAttendue,
                contextLog: '[Backfill] Step 33',
                contextLogData: ['transaction_id' => $tx->id],
            );

            if ($compte === null) {
                Log::warning('[Backfill] Skip : CompteVentilationResolver retourne null', [
                    'transaction_id' => $tx->id,
                    'sous_categorie_id' => $ligne->sous_categorie_id,
                ]);
                $skipDoubleEcriture = true;
                break;
            }

            // Enrichir la ligne legacy avec compte_id + debit/credit
            $montant = (float) $ligne->montant;
            $debit = $tx->type === TypeTransaction::Depense ? $montant : 0.0;
            $credit = $tx->type === TypeTransaction::Recette ? $montant : 0.0;

            $ligne->fill([
                'compte_id' => $compte->id,
                'debit' => $debit,
                'credit' => $credit,
            ])->save();

            $ventilations[] = [
                'compte' => $compte,
                'montant' => $montant,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'notes' => $ligne->notes,
            ];
        }

        if ($skipDoubleEcriture || empty($ventilations)) {
            return false;
        }

        // Résolution du compte de trésorerie
        $modePaiement = $tx->mode_paiement;
        $compteTresorerie = null;

        if ($modePaiement !== null) {
            $isDepense = $tx->type === TypeTransaction::Depense;

            $compteTresorerie = CompteTresorerieResolver::resoudre(
                compteBancaireId: $tx->compte_id !== null ? (int) $tx->compte_id : null,
                mode: $modePaiement,
                contextLog: '[Backfill] Step 33',
                isDepense: $isDepense,
            );

            if ($compteTresorerie === null) {
                Log::warning('[Backfill] Skip : CompteTresorerieResolver retourne null', [
                    'transaction_id' => $tx->id,
                    'mode_paiement' => $modePaiement->value,
                ]);

                return false;
            }
        }

        // Date de la transaction
        $date = $tx->date instanceof \DateTimeInterface
            ? $tx->date
            : new \DateTimeImmutable((string) $tx->date);

        // Appel EcritureGenerator avec existingTransaction
        if ($tx->type === TypeTransaction::Recette) {
            if ($modePaiement !== null) {
                $this->ecritureGenerator->pourRecetteComptant(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    date: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );
            } else {
                $this->ecritureGenerator->pourRecetteACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );
            }
        } else {
            if ($modePaiement !== null) {
                $this->ecritureGenerator->pourDepenseComptant(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    date: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );
            } else {
                $this->ecritureGenerator->pourDepenseACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );
            }
        }

        // Marquer la transaction comme équilibrée (EcritureGenerator l'a validé via assertEquilibre)
        // Pour les nouvelles transactions, EcritureGenerator::createTransactionHeader pose equilibree=true.
        // Pour les existingTransaction, il faut le faire explicitement ici.
        $tx->forceFill(['equilibree' => true])->save();

        return true;
    }
}
