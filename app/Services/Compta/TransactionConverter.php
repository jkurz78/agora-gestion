<?php

declare(strict_types=1);

namespace App\Services\Compta;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\SensVentilation;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Support\MontantDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service de conversion d'une transaction legacy vers le modèle partie double.
 *
 * Stratégie :
 *   1. Charger les lignes de ventilation (compte_id classe 6/7).
 *   2. Valider la classe du compte porté par la ligne.
 *   3. Enrichir les lignes qui ne portent pas encore debit/credit.
 *   4. Appeler EcritureGenerator::pour*() pour créer les lignes PD-only
 *      (411/401, portage, lettrage).
 *   5. Marquer la transaction equilibree=TRUE (via l'observer XOR).
 *
 * Skip silencieux si :
 *   - tiers_id null (OD-like)
 *   - aucune ligne de ventilation
 *   - compte de classe inattendue
 *   - CompteTresorerieResolver retourne null (compte bancaire introuvable)
 *
 * Note : montant_total = 0 n'est PAS un motif de skip. Une gratuité intégrale
 * (ex. commande HelloAsso couverte à 100 % par un code promo) porte des
 * ventilations qui se compensent (ex. 706 crédit / 709A débit, même montant)
 * et DOIT être convertie — voir convertir() pour le détail du raisonnement.
 *
 * Le caller (BackfillPartieDoubleCommand) enveloppe chaque conversion dans DB::transaction.
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
        // PAS de guard sur montant_total = 0 ici — volontairement.
        //
        // Un commit antérieur (12cf366b) skippait toute transaction à montant_total
        // = 0 pour éviter de polluer le grand livre d'écritures nulles. L'intention
        // était juste mais la formulation trop large : elle confondait deux cas très
        // différents que seul le nombre de LIGNES DE VENTILATION distingue —
        //
        //   - aucune ligne de ventilation : un vrai artefact (rien à comptabiliser),
        //     déjà intercepté plus bas par $lignesVentilation->isEmpty().
        //   - des lignes de ventilation qui se COMPENSENT (ex. 706 crédit 50 / 709A
        //     débit 50, une gratuité intégrale HelloAsso) : montant_total vaut 0 mais
        //     c'est le cas normal d'une place offerte, avec un vrai effet comptable —
        //     EcritureGenerator::pourRecetteACredit pose une paire 411 D/C lettrée
        //     (Tâche 7) pour en garder la trace sur le compte du tiers. Le skipper
        //     revient à laisser une commande HelloAsso enregistrée sans écriture,
        //     ce qui a bloqué la clôture d'exercice (transaction #120 en production).
        //
        // Ne réintroduis pas cette garde sur montant_total : le vrai discriminant est
        // $lignesVentilation->isEmpty(), pas le montant net.

        // Guard : tiers_id null → skip (OD-like, pas de lettrage)
        if ($tx->tiers_id === null) {
            Log::info('[Backfill] Skip : tiers_id null', ['transaction_id' => $tx->id]);

            return false;
        }

        /** @var Tiers $tiers */
        $tiers = Tiers::findOrFail($tx->tiers_id);

        $lignesVentilation = TransactionLigne::where('transaction_id', $tx->id)
            ->whereHas('compte', fn ($qq) => $qq->whereIn('classe', [6, 7]))
            ->whereNull('deleted_at')
            ->get();

        if ($lignesVentilation->isEmpty()) {
            Log::info('[Backfill] Skip : aucune ligne de ventilation', ['transaction_id' => $tx->id]);

            return false;
        }

        $classeAttendue = $tx->type === TypeTransaction::Recette ? 7 : 6;
        $ventilations = [];
        $skipDoubleEcriture = false;

        foreach ($lignesVentilation as $ligne) {
            $compte = Compte::find((int) $ligne->compte_id);

            if ($compte === null || (int) $compte->classe !== $classeAttendue) {
                Log::warning('[Backfill] Skip : compte_id porté par la ligne invalide', [
                    'transaction_id' => $tx->id,
                    'transaction_ligne_id' => $ligne->id,
                    'compte_id' => $ligne->compte_id,
                    'classe_attendue' => $classeAttendue,
                ]);
                $skipDoubleEcriture = true;
                break;
            }

            $montant = (float) $ligne->montant;

            // Enrichir la ligne si debit/credit ne sont pas encore posés (idempotent —
            // les lignes compte-first DC-10a arrivent déjà enrichies à la création).
            if ((float) $ligne->debit <= 0.0 && (float) $ligne->credit <= 0.0) {
                $debit = $tx->type === TypeTransaction::Depense ? $montant : 0.0;
                $credit = $tx->type === TypeTransaction::Recette ? $montant : 0.0;

                $ligne->fill([
                    'compte_id' => $compte->id,
                    'debit' => $debit,
                    'credit' => $credit,
                ])->save();
            }

            // Le sens se lit sur la ligne elle-même, jamais déduit du type de la
            // transaction : une ligne au débit (ex. 709A gratuité, contra-produit
            // arrivée déjà enrichie compte-first) doit rester au débit. Le montant
            // passé à EcritureGenerator est toujours la valeur absolue — les
            // montants de ventilation sont strictement positifs, le sens est porté
            // à part (SensVentilation).
            $sensLigne = (float) $ligne->debit > 0.0 ? SensVentilation::Debit : SensVentilation::Credit;

            $ventilations[] = [
                'compte' => $compte,
                'montant' => abs($montant),
                'sens' => $sensLigne,
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

                // Saut de la T2 quand le net encaissable est nul (gratuité intégrale,
                // ex. commande HelloAsso couverte à 100 % par un code promo) : le 411
                // est déjà soldé par la paire lettrée que pourRecetteACredit vient de
                // poser (Tâche 7) — il n'y a rien à encaisser. Appeler
                // pourEncaissementCreance() dans ce cas lèverait
                // LettrageDejaPresentException (la ligne 411 source est déjà lettrée),
                // que le catch (\Throwable) de la synchro HelloAsso avalerait en simple
                // warning : la transaction resterait legacy sans qu'aucun signal
                // d'échec ne remonte. On marque directement la transaction équilibrée
                // (fait en fin de méthode, commun à tous les chemins) et on s'arrête là.
                if ($this->netEncaissableCentimes($ventilations) !== 0) {
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

    /**
     * Net encaissable (Σcrédit − Σdébit) en centimes depuis les $ventilations
     * construites par convertir(), jamais une somme de flottants (MontantDecimal).
     *
     * @param  list<array{compte: Compte, montant: float, sens: SensVentilation, operation_id: mixed, seance: mixed, notes: mixed}>  $ventilations
     */
    private function netEncaissableCentimes(array $ventilations): int
    {
        $netCentimes = 0;

        foreach ($ventilations as $ventilation) {
            $montantCentimes = MontantDecimal::versCentimes(
                number_format((float) $ventilation['montant'], 2, '.', '')
            );

            $netCentimes += $ventilation['sens'] === SensVentilation::Credit
                ? $montantCentimes
                : -$montantCentimes;
        }

        return $netCentimes;
    }
}
