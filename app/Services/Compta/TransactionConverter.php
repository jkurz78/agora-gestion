<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\StatutReglement;
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
 *   - montant_total = 0 (inscription gratuite HelloAsso, artifact sans effet comptable)
 *   - tiers_id null (transactions sans tiers — OD-like)
 *   - sous_categorie_id null sur une ligne (ligne sans catégorie)
 *   - CompteVentilationResolver retourne null (SC sans code_cerfa ou compte introuvable)
 *   - CompteTresorerieResolver retourne null (compte bancaire introuvable)
 *
 * Le caller (BackfillPartieDoubleCommand) enveloppe chaque conversion dans DB::transaction.
 *
 * Note : le pre-nettoyage auto-délettrage avant conversion est géré via
 * LettrageService::autoDelettrerLignesDe (mutualisé en Vague 3b — rule-of-three).
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
        // Guard : montant_total = 0 → skip (inscription gratuite HelloAsso ou équivalent).
        // Une transaction à 0€ n'a aucun effet comptable — générer des écritures PD nulles
        // polluerait le grand livre sans valeur. L'adhésion associée reste valide.
        if (bccomp((string) $tx->montant_total, '0.00', 2) === 0) {
            Log::info('[Backfill] Skip : montant_total = 0, aucune écriture PD générée', [
                'transaction_id' => $tx->id,
            ]);

            return false;
        }

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

        // Date de la transaction
        $date = $tx->date instanceof \DateTimeInterface
            ? $tx->date
            : new \DateTimeImmutable((string) $tx->date);

        // -----------------------------------------------------------------------
        // Routage sur le TRIPLET (statut_reglement, remise_id, rapprochement_id)
        //
        // Discriminant #1 : en_attente → créance/dette only (jamais comptant),
        // QUELS QUE SOIENT mode_paiement et remise_id/rapprochement_id.
        // Note : statut null (dons, factures comptant, etc.) → chemin comptant (cas 4).
        // -----------------------------------------------------------------------
        if ($tx->statut_reglement === StatutReglement::EnAttente) {
            // Cas en_attente : 411 D / 7x C seulement — pas de portage, pas de lettrage.
            if ($tx->type === TypeTransaction::Recette) {
                $this->ecritureGenerator->pourRecetteACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
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
        } else {
            // Cas Recu / Pointe / null → T2 séparée (chantier 2b/3b convergence).
            // Même pattern que TransactionService::enrichirPartieDouble :
            //   Recette : pourRecetteACredit() → T1 enrichie, pourEncaissementCreance() → T2
            //   Dépense : pourDepenseACredit() → T1 enrichie, pourReglementFournisseur() → T2
            $modePaiement = $tx->mode_paiement;

            if ($modePaiement === null) {
                Log::info('[Backfill] Skip : mode_paiement null hors en_attente (OD-like)', [
                    'transaction_id' => $tx->id,
                ]);

                return false;
            }

            $sens = $tx->type === TypeTransaction::Depense ? Sens::Depense : Sens::Recette;

            $compteTresorerie = CompteTresorerieResolver::resoudre(
                compteBancaireId: $tx->compte_id !== null ? (int) $tx->compte_id : null,
                mode: $modePaiement,
                contextLog: 'TransactionConverter',
                sens: $sens,
            );

            if ($compteTresorerie === null) {
                Log::warning('[Backfill] Skip : CompteTresorerieResolver retourne null', [
                    'transaction_id' => $tx->id,
                    'mode_paiement' => $modePaiement->value,
                ]);

                return false;
            }

            // Override portage pour le cas « chèque pointé direct » (recette uniquement) :
            // remise_id null + rapprochement_id non null + mode Cheque.
            // Sans override, resoudreComptePortage forcerait 5112 ; le clone prod prouve
            // que le portage doit être sur le 512X bancaire.
            $comptePortageOverride = null;

            if (
                $tx->type === TypeTransaction::Recette
                && $modePaiement === ModePaiement::Cheque
                && $tx->remise_id === null
                && $tx->rapprochement_id !== null
            ) {
                $comptePortageOverride = $compteTresorerie;
            }

            if ($tx->type === TypeTransaction::Recette) {
                // Step 1 : T1 créance (411 D / 7xx C, journal=Vente)
                $this->ecritureGenerator->pourRecetteACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );

                // Step 2 : T2 encaissement séparée (portage D / 411 C, journal=Banque)
                $libelleEncaissement = 'Encaissement '.$tx->libelle;
                $t2 = $this->ecritureGenerator->pourEncaissementCreance(
                    transactionCreance: $tx,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $date,
                    libelle: $libelleEncaissement,
                    comptePortageOverride: $comptePortageOverride,
                );

                // Propager rapprochement_id sur la T2 pour rapprochement direct
                // (virement/CB/chèque pointé : c'est la T2 qui porte le mouvement 512X).
                // Si remise_id est présent, le rapprochement va sur la T4 (Phase 2 du backfill).
                if ($tx->rapprochement_id !== null && $tx->remise_id === null) {
                    $t2->forceFill(['rapprochement_id' => $tx->rapprochement_id])->save();
                }
            } else {
                // Step 1 : T1 dette (6xx D / 401 C, journal=Achat)
                $this->ecritureGenerator->pourDepenseACredit(
                    tiers: $tiers,
                    ventilations: $ventilations,
                    dateConstatation: $date,
                    libelle: $tx->libelle,
                    existingTransaction: $tx,
                );

                // Step 2 : T2 règlement séparée (401 D / portage C, journal=Banque)
                $libelleReglement = 'Règlement '.$tx->libelle;
                $t2 = $this->ecritureGenerator->pourReglementFournisseur(
                    transactionDette: $tx,
                    mode: $modePaiement,
                    compteTresorerie: $compteTresorerie,
                    datePaiement: $date,
                    libelle: $libelleReglement,
                );

                // Propager rapprochement_id sur la T2 pour rapprochement direct
                if ($tx->rapprochement_id !== null && $tx->remise_id === null) {
                    $t2->forceFill(['rapprochement_id' => $tx->rapprochement_id])->save();
                }
            }
        }

        // Marquer la transaction comme équilibrée (EcritureGenerator l'a validé via assertEquilibre)
        // Pour les nouvelles transactions, EcritureGenerator::createTransactionHeader pose equilibree=true.
        // Pour les existingTransaction, il faut le faire explicitement ici.
        $tx->forceFill(['equilibree' => true])->save();

        return true;
    }
}
