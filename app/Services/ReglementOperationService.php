<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\Sens;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\Compte;
use App\Models\CompteBancaire;
use App\Models\Operation;
use App\Models\Reglement;
use App\Models\Seance;
use App\Models\Tiers;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\CompteVentilationResolver;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
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
            Log::warning('[PartieDouble][ReglementOperationService] — skip total : typeOperation sans sous_categorie_id', [
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
     * @deprecated Utiliser marquerRegle() à la place (unifié 411/401, agnostique au type).
     *
     * Pattern Step 24 (FactureService::encaisserPartieDouble) reproduit ici.
     *
     * Gardes skip silencieux (best-effort — la mise à jour statut_reglement est préservée) :
     * — mode_paiement null sur T1 et $mode null → skip T2 (encaisserSiNonEncaisse)
     * — mode nécessitant 512X et compte 512X introuvable (IBAN non matché)
     * — T1 ne porte pas de ligne 411 (transaction legacy sans double écriture)
     * — ligne 411 de T1 déjà lettrée (idempotence — voir encaisserSiNonEncaisse)
     *
     * @param  ModePaiement|null  $mode  Mode d'encaissement fourni pour une créance (mode_paiement null).
     *                                   Si null, comportement rétro-compatible inchangé.
     * @param  int|null  $compteId  Compte bancaire cible optionnel (remplace compte_id si fourni).
     */
    public function marquerRecu(Transaction $transaction, ?ModePaiement $mode = null, ?int $compteId = null): void
    {
        $this->marquerRegle($transaction, $mode, $compteId);
    }

    /**
     * Point d'entrée idempotent pour générer T2 (encaissement créance) sur une transaction T1.
     *
     * @deprecated Utiliser reglerOuEncaisser() à la place (unifié 411/401, agnostique au type).
     *
     * Peut être appelé par n'importe quel déclencheur (marquerRecu, remise, pointage) sans risque
     * de double-écriture : si la ligne 411 de T1 est déjà lettrée, la méthode est un no-op silencieux.
     *
     * Ne touche PAS à statut_reglement — c'est la responsabilité du caller.
     *
     * Volontairement INDÉPENDANT du flag config('compta.use_partie_double') : les callers
     * (marquerRecu, remise) l'appellent sans condition. C'est sûr car une transaction legacy
     * pré-cutover n'a pas de ligne 411 → la garde « 411 absent » ci-dessous en fait un no-op.
     * La garde 411 EST le gate de fait, le flag n'est pas requis ici.
     *
     * Skip silencieux (sans exception) dans les cas suivants :
     * — mode_paiement null sur T1
     * — compte de trésorerie non résolu (IBAN non matché)
     * — compte 411 absent du tenant (schéma partie double non posé)
     * — T1 ne porte pas de ligne 411, ou la ligne 411 n'a pas de tiers
     * — ligne 411 déjà lettrée (lettrage_code !== null) — guard principal d'idempotence
     *
     * Identique à FactureService::encaisserPartieDouble (Step 24) — sans attache pivot facture_transaction.
     *
     * @param  Compte|null  $compte411  Compte 411 pré-chargé (optimisation N+1). Résolu ici si null.
     */
    public function encaisserSiNonEncaisse(Transaction $transaction, ?Compte $compte411 = null): void
    {
        $this->reglerOuEncaisser($transaction);
    }

    /**
     * Marque une transaction de dépense comme payée + génère T2 (règlement) partie double.
     *
     * @deprecated Utiliser marquerRegle() à la place (unifié 411/401, agnostique au type).
     *
     * Miroir de marquerRecu() pour les dépenses (compte 401 au lieu de 411).
     *
     * Gardes skip silencieux (best-effort — la mise à jour statut_reglement est préservée) :
     * — mode_paiement null sur T1 et $mode null → skip T2 (reglerSiNonRegle)
     * — mode nécessitant 512X et compte 512X introuvable (IBAN non matché)
     * — T1 ne porte pas de ligne 401 (transaction legacy sans double écriture)
     * — ligne 401 de T1 déjà lettrée (idempotence — voir reglerSiNonRegle)
     *
     * @param  ModePaiement|null  $mode  Mode de règlement fourni pour une dette (mode_paiement null).
     *                                   Si null, comportement rétro-compatible inchangé.
     * @param  int|null  $compteId  Compte bancaire cible optionnel (remplace compte_id si fourni).
     */
    public function marquerPaye(Transaction $transaction, ?ModePaiement $mode = null, ?int $compteId = null): void
    {
        $this->marquerRegle($transaction, $mode, $compteId);
    }

    /**
     * Point d'entrée idempotent pour générer T2 (règlement dette fournisseur) sur une transaction T1.
     *
     * @deprecated Utiliser reglerOuEncaisser() à la place (unifié 411/401, agnostique au type).
     *
     * Miroir de encaisserSiNonEncaisse() pour les dépenses (compte 401 au lieu de 411).
     *
     * Peut être appelé par n'importe quel déclencheur (marquerPaye, rapprochement) sans risque
     * de double-écriture : si la ligne 401 de T1 est déjà lettrée, la méthode est un no-op silencieux.
     *
     * Ne touche PAS à statut_reglement — c'est la responsabilité du caller.
     *
     * Skip silencieux (sans exception) dans les cas suivants :
     * — mode_paiement null sur T1
     * — compte de trésorerie non résolu (IBAN non matché)
     * — compte 401 absent du tenant (schéma partie double non posé)
     * — T1 ne porte pas de ligne 401, ou la ligne 401 n'a pas de tiers
     * — ligne 401 déjà lettrée (lettrage_code !== null) — guard principal d'idempotence
     *
     * @param  Compte|null  $compte401  Compte 401 pré-chargé (optimisation N+1). Résolu ici si null.
     */
    public function reglerSiNonRegle(Transaction $transaction, ?Compte $compte401 = null): void
    {
        $this->reglerOuEncaisser($transaction);
    }

    /**
     * Point d'entrée idempotent unifié pour générer T2 sur une T1.
     *
     * Remplace encaisserSiNonEncaisse (411) et reglerSiNonRegle (401).
     * Lit la ligne tiers ouverte (411 ou 401, non lettrée) sans brancher sur le type.
     *
     * Skip silencieux si :
     * — mode_paiement null sur T1
     * — ligne tiers ouverte introuvable (legacy sans PD, ou déjà lettrée)
     * — compte de trésorerie non résolu
     */
    public function reglerOuEncaisser(Transaction $transaction): void
    {
        $mode = $transaction->mode_paiement;

        if ($mode === null) {
            Log::warning('[PartieDouble][ReglementOperationService] — skip reglerOuEncaisser : mode_paiement null sur T1', [
                'transaction_id' => (int) $transaction->id,
            ]);

            return;
        }

        // Trouver la ligne tiers ouverte (411 ou 401, non lettrée, avec tiers)
        $ligneTiers = TransactionLigne::where('transaction_id', (int) $transaction->id)
            ->whereNull('lettrage_code')
            ->whereNotNull('tiers_id')
            ->whereHas('compte', fn ($q) => $q->whereIn('numero_pcg', ['411', '401']))
            ->first();

        if ($ligneTiers === null) {
            Log::info('[PartieDouble][ReglementOperationService] — skip reglerOuEncaisser : pas de ligne tiers ouverte (legacy ou déjà lettrée)', [
                'transaction_id' => (int) $transaction->id,
            ]);

            return;
        }

        // Direction D/C → Sens pour CompteTresorerieResolver
        $sens = (float) $ligneTiers->debit > 0 ? Sens::Recette : Sens::Depense;

        $compteTresorerie = CompteTresorerieResolver::resoudre(
            compteBancaireId: $transaction->compte_id !== null ? (int) $transaction->compte_id : null,
            mode: $mode,
            contextLog: 'ReglementOperationService::reglerOuEncaisser',
            sens: $sens,
        );

        if ($compteTresorerie === null) {
            return;
        }

        $this->ecritureGenerator->pourReglement(
            t1: $transaction,
            mode: $mode,
            compteTresorerie: $compteTresorerie,
            datePaiement: $transaction->date,
        );
    }

    /**
     * Marque une transaction comme réglée + génère T2 partie double.
     *
     * Unifie marquerRecu (411) et marquerPaye (401). Agnostique au type.
     * Opère via reglerOuEncaisser qui lit la ligne tiers ouverte.
     *
     * @param  ModePaiement|null  $mode  Mode fourni pour une créance/dette sans mode.
     * @param  int|null  $compteId  Compte bancaire cible optionnel.
     */
    public function marquerRegle(Transaction $transaction, ?ModePaiement $mode = null, ?int $compteId = null): void
    {
        if ($transaction->statut_reglement !== StatutReglement::EnAttente) {
            return;
        }

        if ($transaction->isLockedByRapprochement() || $transaction->isLockedByFacture()) {
            return;
        }

        DB::transaction(function () use ($transaction, $mode, $compteId): void {
            $updateData = ['statut_reglement' => StatutReglement::Recu->value];
            if ($transaction->mode_paiement === null && $mode !== null) {
                $updateData['mode_paiement'] = $mode->value;
                if ($compteId !== null) {
                    $updateData['compte_id'] = $compteId;
                }
            }

            $transaction->update($updateData);
            $transaction->refresh();

            $this->reglerOuEncaisser($transaction);

            app(EtatReglementResolver::class)->syncer($transaction->fresh());
        });
    }

    /**
     * Retrouve la transaction d'encaissement T2 associée à une source T1, si elle est séparée.
     *
     * Principe : T1 et T2 partagent un `lettrage_code` sur leur ligne 411 respective.
     * Cas lumped (ligne 411 source lettrée avec une autre ligne 411 sur la MÊME transaction) :
     *   → retourne null (portage déjà sur T1, pas de T2 séparé).
     * Cas séparé (ligne 411 lettrée sur une AUTRE transaction) :
     *   → retourne cette autre transaction (= T2).
     * Cas non-lettré (pas encore encaissé) :
     *   → retourne null.
     *
     * Utilisé par RemiseBancaireService::recreerT4 et RapprochementBancaireService::toggleTransaction
     * pour localiser la ligne portage (5112/512x) et propager/effacer rapprochement_id.
     *
     * @param  Compte|null  $compte411  Compte 411 pré-chargé (optimisation N+1). Résolu ici si null.
     */
    public function trouverEncaissementT2(Transaction $t1, ?Compte $compte411 = null): ?Transaction
    {
        $compte411 ??= Compte::ofNumero('411');

        if ($compte411 === null) {
            return null;
        }

        $ligne411T1 = TransactionLigne::where('transaction_id', (int) $t1->id)
            ->where('compte_id', (int) $compte411->id)
            ->whereNotNull('lettrage_code')
            ->first();

        if ($ligne411T1 === null) {
            return null; // Pas encore lettré → pas de T2
        }

        // Chercher la ligne 411 partageant le même code sur une AUTRE transaction
        $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
            ->where('compte_id', (int) $compte411->id)
            ->where('transaction_id', '!=', (int) $t1->id)
            ->first();

        if ($ligne411T2 === null) {
            return null; // Lumped : la contrepartie est sur la même transaction
        }

        return Transaction::find($ligne411T2->transaction_id);
    }

    /**
     * Trouve la T2 de règlement d'une dépense T1 dette via le lettrage du 401.
     *
     * Symétrique de trouverEncaissementT2 (recettes, 411) pour les dépenses (401).
     * Utilisé par RapprochementBancaireService::toggleTransaction pour propager
     * rapprochement_id sur la T2 au pointage/dépointage d'une dépense comptant.
     *
     * @param  Compte|null  $compte401  Compte 401 pré-chargé (optimisation N+1). Résolu ici si null.
     */
    public function trouverReglementT2(Transaction $t1, ?Compte $compte401 = null): ?Transaction
    {
        $compte401 ??= Compte::ofNumero('401');

        if ($compte401 === null) {
            return null;
        }

        $ligne401T1 = TransactionLigne::where('transaction_id', (int) $t1->id)
            ->where('compte_id', (int) $compte401->id)
            ->whereNotNull('lettrage_code')
            ->first();

        if ($ligne401T1 === null) {
            return null; // Pas encore lettré → pas de T2
        }

        // Chercher la ligne 401 partageant le même code sur une AUTRE transaction
        $ligne401T2 = TransactionLigne::where('lettrage_code', $ligne401T1->lettrage_code)
            ->where('compte_id', (int) $compte401->id)
            ->where('transaction_id', '!=', (int) $t1->id)
            ->first();

        if ($ligne401T2 === null) {
            return null; // Lumped : la contrepartie est sur la même transaction
        }

        return Transaction::find($ligne401T2->transaction_id);
    }

    /**
     * Trouve la T2 (encaissement ou règlement) liée à une T1, via la ligne tiers lettrée.
     *
     * Unifie trouverEncaissementT2 (411) et trouverReglementT2 (401). Agnostique au type :
     * cherche la ligne tiers (411 ou 401) lettrée sur T1, puis la ligne partageant le même
     * lettrage_code sur une AUTRE transaction.
     *
     * Retourne null si : pas de ligne tiers lettrée, cas lumpé (contrepartie sur même tx),
     * ou pas de T2 séparée.
     */
    public function trouverT2(Transaction $t1): ?Transaction
    {
        $ligneTiers = TransactionLigne::where('transaction_id', (int) $t1->id)
            ->whereNotNull('lettrage_code')
            ->whereHas('compte', fn ($q) => $q->whereIn('numero_pcg', ['411', '401']))
            ->first();

        if ($ligneTiers === null) {
            return null;
        }

        $ligneT2 = TransactionLigne::where('lettrage_code', $ligneTiers->lettrage_code)
            ->where('compte_id', (int) $ligneTiers->compte_id)
            ->where('transaction_id', '!=', (int) $t1->id)
            ->first();

        return $ligneT2 !== null ? Transaction::find($ligneT2->transaction_id) : null;
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
        $compte = CompteVentilationResolver::resoudre(
            sousCategorieId: $sousCategorieId,
            classeAttendue: 7,
            contextLog: 'ReglementOperationService',
            contextLogData: ['transaction_id' => (int) $tx->id],
        );

        if ($compte === null) {
            // Garde loggée dans CompteVentilationResolver::resoudre
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
}
