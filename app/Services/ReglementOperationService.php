<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Exceptions\Compta\LettrageDejaPresentException;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\SousCategorie;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\EcritureGenerator;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service métier pour l'onglet Règlements d'une opération (ReglementTable Livewire).
 *
 * Step 26 : branche comptabiliserSeance() + marquerRecu() sur le moteur partie double.
 *
 * Stratégie (alternative pragmatique — voir décisions Step 26) :
 * — comptabiliserSeance : crée Transaction + TransactionLigne legacy directement (pattern FactureService),
 *   puis enrichit via EcritureGenerator::pourRecetteACredit(existingTransaction: $tx).
 *   Pas de modification de TransactionService::enrichirPartieDouble (évite routing implicite EnAttente).
 * — marquerRecu : toggle statut_reglement = Recu + génère T2 via pourEncaissementCreance
 *   + auto-lettrage 411 (pattern Step 24 FactureService::encaisserPartieDouble).
 */
final class ReglementOperationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly NumeroPieceService $numeroPiece,
    ) {}

    /**
     * Comptabilise tous les règlements sans transaction d'une séance.
     *
     * Crée N Transactions (1 par Reglement avec montant > 0 et sans tx existante)
     * avec statut_reglement = EnAttente (créance). Enrichit chaque Transaction avec
     * les écritures partie double via pourRecetteACredit.
     *
     * Skip silencieux (best-effort) si les prérequis partie double ne sont pas satisfaits
     * (sous_categorie sans code_cerfa, compte classe 7 introuvable, etc.).
     *
     * @param  Seance  $seance  Séance dont on comptabilise les règlements.
     * @param  int  $compteBancaireId  ID CompteBancaire sélectionné dans l'UI.
     * @param  Carbon  $date  Date de la transaction.
     */
    public function comptabiliserSeance(
        Seance $seance,
        int $compteBancaireId,
        Carbon $date,
    ): void {
        $seance->load('operation.typeOperation');
        $operation = $seance->operation;
        $sousCategorieId = $operation->typeOperation?->sous_categorie_id;

        if ($sousCategorieId === null) {
            Log::warning('[PartieDouble] Step 26 — skip total : typeOperation sans sous_categorie_id', [
                'seance_id' => (int) $seance->id,
                'operation_id' => (int) $operation->id,
            ]);

            return;
        }

        // Règlements sans transaction existante, avec montant > 0.
        // Guard multi-tenant : Reglement n'a pas de association_id propre → dérivé via Participant.
        $reglements = Reglement::with('participant.tiers')
            ->where('seance_id', (int) $seance->id)
            ->whereHas('participant', fn ($q) => $q->where('association_id', (int) TenantContext::currentId()))
            ->where('montant_prevu', '>', 0)
            ->whereDoesntHave('transaction')
            ->get();

        if ($reglements->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($reglements, $seance, $operation, $sousCategorieId, $compteBancaireId, $date): void {
            foreach ($reglements as $reglement) {
                $tiers = $reglement->participant->tiers;
                $libelle = "Règlement {$tiers->displayName()} — {$operation->nom} S{$seance->numero}";

                // 1. Crée la Transaction entête (créance)
                $tx = Transaction::create([
                    'association_id' => (int) TenantContext::currentId(),
                    'type' => TypeTransaction::Recette->value,
                    'date' => $date->toDateString(),
                    'numero_piece' => $this->numeroPiece->assign($date),
                    'libelle' => $libelle,
                    'montant_total' => $reglement->montant_prevu,
                    'mode_paiement' => $reglement->mode_paiement?->value,
                    'tiers_id' => (int) $tiers->id,
                    'compte_id' => $compteBancaireId,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reglement_id' => (int) $reglement->id,
                    'saisi_par' => auth()->id(),
                ]);

                // 2. Crée la TransactionLigne legacy
                $ligne = TransactionLigne::create([
                    'transaction_id' => (int) $tx->id,
                    'sous_categorie_id' => $sousCategorieId,
                    'operation_id' => (int) $operation->id,
                    'seance' => $seance->numero,
                    'montant' => $reglement->montant_prevu,
                ]);

                // 3. Enrichit partie double (best-effort — n'annule pas la création legacy)
                $this->enrichirCreancePartieDouble($tx, $ligne, $tiers, $operation, $seance, $sousCategorieId);
            }
        });
    }

    /**
     * Marque une transaction de règlement comme reçue + génère T2 (encaissement) partie double.
     *
     * Pattern Step 24 (FactureService::encaisserPartieDouble) reproduit ici.
     *
     * Gardes skip silencieux (best-effort — la mise à jour statut_reglement est préservée) :
     * — mode_paiement null sur T1
     * — mode nécessitant 512X et compte 512X introuvable (IBAN non matché)
     * — T1 ne porte pas de ligne 411 (transaction legacy sans double écriture)
     *
     * Garde forte propagée : LettrageDejaPresentException si ligne 411 déjà lettrée.
     *
     * @throws LettrageDejaPresentException Si ligne 411 déjà lettrée (double encaissement).
     */
    public function marquerRecu(Transaction $transaction): void
    {
        if ($transaction->statut_reglement !== StatutReglement::EnAttente) {
            return;
        }

        if ($transaction->isLockedByRapprochement() || $transaction->isLockedByFacture()) {
            return;
        }

        DB::transaction(function () use ($transaction): void {
            // 1. Toggle statut (legacy — préservé dans tous les cas)
            $transaction->update(['statut_reglement' => StatutReglement::Recu->value]);

            // 2. Partie double : génère T2 si T1 porte une ligne 411 valide
            $this->encaisserPartieDouble($transaction);
        });
    }

    /**
     * Enrichit la Transaction créée par comptabiliserSeance avec les écritures partie double.
     *
     * Pattern Step 23 (FactureService::genererTransactionDepuisLignesManuelles) :
     * — résout la SousCategorie → Compte classe 7
     * — enrichit la ligne legacy (compte_id / debit / credit)
     * — délègue à EcritureGenerator::pourRecetteACredit(existingTransaction: $tx)
     *
     * Skippe silencieusement (Log::warning) si un prérequis est absent.
     */
    private function enrichirCreancePartieDouble(
        Transaction $tx,
        TransactionLigne $ligne,
        Tiers $tiers,
        Operation $operation,
        Seance $seance,
        int $sousCategorieId,
    ): void {
        // --- Résolution SousCategorie → Compte classe 7 ---
        $compte = $this->resoudreCompteVentilationRecette($ligne, (int) $tx->id, $sousCategorieId);

        if ($compte === null) {
            // Garde loggée dans resoudreCompteVentilationRecette
            return;
        }

        // --- Enrichir la ligne legacy avec colonnes partie double (recette → crédit) ---
        $montant = (float) $ligne->montant;
        $ligne->fill([
            'compte_id' => $compte->id,
            'debit' => 0.0,
            'credit' => $montant,
        ])->save();

        // --- Ventilation pour EcritureGenerator ---
        $ventilations = [
            [
                'compte' => $compte,
                'montant' => $montant,
                'operation_id' => (int) $operation->id,
                'seance' => $seance->numero,
                'notes' => null,
            ],
        ];

        // --- Délègue à EcritureGenerator (ajoute ligne 411 D tiers) ---
        // existingTransaction : skippe createTransactionHeader + création ventilations (déjà faites).
        $this->ecritureGenerator->pourRecetteACredit(
            tiers: $tiers,
            ventilations: $ventilations,
            dateConstatation: $tx->date instanceof \DateTimeInterface
                ? $tx->date
                : new \DateTimeImmutable((string) $tx->date),
            libelle: $tx->libelle,
            existingTransaction: $tx,
        );
    }

    /**
     * Génère T2 (encaissement créance) via EcritureGenerator::pourEncaissementCreance.
     *
     * Skip silencieux (best-effort) sur 4 cas. Garde forte sur double lettrage (exception).
     * Identique à FactureService::encaisserPartieDouble (Step 24) — sans attache pivot facture_transaction.
     *
     * @throws LettrageDejaPresentException Si ligne 411 déjà lettrée.
     */
    private function encaisserPartieDouble(Transaction $transaction): void
    {
        // --- 1. Résolution mode de paiement ---
        /** @var ModePaiement|null $mode */
        $mode = $transaction->mode_paiement;

        if ($mode === null) {
            Log::warning('[PartieDouble] Step 26 — skip : mode_paiement null sur T1', [
                'transaction_id' => (int) $transaction->id,
            ]);

            return;
        }

        // --- 2. Résolution compte de trésorerie (CompteBancaire → 512X via IBAN, ou placeholder 5112) ---
        $compteTresorerie = CompteTresorerieResolver::resoudre(
            compteBancaireId: $transaction->compte_id !== null ? (int) $transaction->compte_id : null,
            mode: $mode,
            contextLog: 'Step 26',
            isDepense: false, // encaissement créance = côté recette
        );

        if ($compteTresorerie === null) {
            // Skip silencieux déjà loggué par CompteTresorerieResolver
            return;
        }

        // --- 3. Vérifie que T1 porte une ligne 411 ---
        $compte411 = Compte::ofNumero('411');
        if ($compte411 === null) {
            Log::warning('[PartieDouble] Step 26 — skip : compte 411 absent (tenant sans schéma PD)', [
                'transaction_id' => (int) $transaction->id,
            ]);

            return;
        }

        $ligne411 = TransactionLigne::where('transaction_id', (int) $transaction->id)
            ->where('compte_id', (int) $compte411->id)
            ->first();

        if ($ligne411 === null || $ligne411->tiers_id === null) {
            Log::warning('[PartieDouble] Step 26 — skip : T1 legacy sans ligne 411 ou sans tiers', [
                'transaction_id' => (int) $transaction->id,
            ]);

            return;
        }

        // --- 4. Délègue à EcritureGenerator (crée T2 + auto-lettre la paire 411) ---
        // LettrageDejaPresentException propagée telle quelle → rollback DB::transaction englobante.
        $this->ecritureGenerator->pourEncaissementCreance(
            transactionCreance: $transaction,
            mode: $mode,
            compteTresorerie: $compteTresorerie,
            datePaiement: now(),
            libelle: 'Encaissement règlement séance',
        );
    }

    /**
     * Résout le Compte de ventilation (classe 7) depuis la SousCategorie d'une ligne legacy.
     *
     * TODO DRY : logique identique à FactureService::resoudreCompteVentilationRecette
     * et TransactionService::enrichirPartieDouble. À extraire en helper partagé post-Step 27.
     */
    private function resoudreCompteVentilationRecette(
        TransactionLigne $ligne,
        int $transactionId,
        int $sousCategorieId,
    ): ?Compte {
        /** @var SousCategorie|null $sousCat */
        $sousCat = SousCategorie::find($sousCategorieId);

        if ($sousCat === null || $sousCat->code_cerfa === null) {
            Log::warning('[PartieDouble] Step 26 — skip : sous-catégorie sans code_cerfa', [
                'transaction_id' => $transactionId,
                'sous_categorie_id' => $sousCategorieId,
            ]);

            return null;
        }

        /** @var Compte|null $compte */
        $compte = Compte::ofNumero($sousCat->code_cerfa);

        if ($compte === null) {
            Log::warning('[PartieDouble] Step 26 — skip : compte introuvable pour code_cerfa', [
                'transaction_id' => $transactionId,
                'code_cerfa' => $sousCat->code_cerfa,
            ]);

            return null;
        }

        if ((int) $compte->classe !== 7) {
            Log::warning('[PartieDouble] Step 26 — skip : classe compte ≠ 7 (recette attendue)', [
                'transaction_id' => $transactionId,
                'numero_pcg' => $compte->numero_pcg,
                'classe' => $compte->classe,
            ]);

            return null;
        }

        return $compte;
    }
}
