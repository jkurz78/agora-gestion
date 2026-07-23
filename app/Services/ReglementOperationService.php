<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Compta\PosteTiersReglementData;
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
use App\Services\Compta\ANouveau\PosteReporteResolver;
use App\Services\Compta\CompteTresorerieResolver;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\PartieDoubleGuard;
use App\Services\Compta\PostesTiersOuvertsService;
use App\Services\Compta\PosteTiersReglementService;
use App\Tenant\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service métier pour l'onglet Règlements d'une opération (ReglementTable Livewire).
 *
 * Les anciens boutons marquerRecu/marquerPaye délèguent temporairement au
 * service daté des postes tiers. Les traitements internes best-effort
 * reglerOuEncaisser/encaisserSiNonEncaisse restent disponibles jusqu'à la
 * migration complète des interfaces.
 */
final class ReglementOperationService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly NumeroPieceService $numeroPiece,
        private readonly PosteReporteResolver $posteReporteResolver,
        private readonly PostesTiersOuvertsService $postesOuverts,
        private readonly PosteTiersReglementService $posteTiersReglementService,
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Comptabilise tous les règlements sans transaction d'une séance.
     *
     * Crée N Transactions (1 par Reglement avec montant > 0 et sans tx existante)
     * avec statut_reglement = EnAttente (créance). Enrichit chaque Transaction avec
     * les écritures partie double via pourRecetteACredit.
     *
     * Skip silencieux (best-effort) si les prérequis partie double ne sont pas satisfaits
     * (compte de classe 7 introuvable, etc.).
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
        // DC-10a — la ventilation est portée par typeOperation.compte_id (source unique).
        $compteId = $operation->typeOperation?->compte_id;

        if ($compteId === null) {
            Log::warning('[PartieDouble][ReglementOperationService] — skip total : typeOperation sans compte_id', [
                'seance_id' => (int) $seance->id,
                'operation_id' => (int) $operation->id,
            ]);

            return;
        }

        // Validation unique du compte de ventilation (classe 7 attendue pour une recette).
        // Best-effort préservé : compte invalide → lignes créées sans PD (skip loggué).
        $compteVentilation = Compte::find((int) $compteId);
        if ($compteVentilation === null || (int) $compteVentilation->classe !== 7) {
            Log::warning('[PartieDouble][ReglementOperationService] — compte_id du typeOperation invalide (classe ≠ 7), PD skippée', [
                'operation_id' => (int) $operation->id,
                'compte_id' => (int) $compteId,
            ]);
            $compteVentilation = null;
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

        DB::transaction(function () use ($reglements, $seance, $operation, $compteVentilation, $compteBancaireId, $date): void {
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

                // 2. Crée la ligne de ventilation compte-first : compte_id + debit/credit
                // posés à la création (recette → crédit), invariant XOR satisfait d'emblée.
                // Compte invalide → ligne sans compte (PD skippée, best-effort).
                $ligne = TransactionLigne::create([
                    'transaction_id' => (int) $tx->id,
                    'compte_id' => $compteVentilation?->id,
                    'operation_id' => (int) $operation->id,
                    'seance' => $seance->numero,
                    'montant' => $reglement->montant_prevu,
                    'debit' => 0,
                    'credit' => $compteVentilation !== null ? (float) $reglement->montant_prevu : 0,
                ]);

                // 3. Enrichit partie double (best-effort — n'annule pas la création de la ligne)
                $this->enrichirCreancePartieDouble($tx, $ligne, $tiers, $operation, $seance, $compteVentilation);
            }
        });
    }

    /**
     * Solde le poste client restant via le service de règlement daté.
     *
     * @deprecated Les interfaces utilisateur doivent ouvrir PosteTiersReglementModal.
     *
     * @param  ModePaiement|null  $mode  Mode d'encaissement fourni pour une créance (mode_paiement null).
     *                                   Si null, comportement rétro-compatible inchangé.
     * @param  int|null  $compteId  Compte bancaire cible optionnel (remplace compte_id si fourni).
     */
    public function marquerRecu(
        Transaction $transaction,
        ?ModePaiement $mode = null,
        ?int $compteId = null,
    ): void {
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
     * Solde le poste fournisseur restant via le service de règlement daté.
     *
     * @deprecated Les interfaces utilisateur doivent ouvrir PosteTiersReglementModal.
     *
     * @param  ModePaiement|null  $mode  Mode de règlement fourni pour une dette (mode_paiement null).
     *                                   Si null, comportement rétro-compatible inchangé.
     * @param  int|null  $compteId  Compte bancaire cible optionnel (remplace compte_id si fourni).
     */
    public function marquerPaye(
        Transaction $transaction,
        ?ModePaiement $mode = null,
        ?int $compteId = null,
    ): void {
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
        $ligneTiers = $this->posteReporteResolver->dernierePourTransaction($transaction);

        if ($ligneTiers === null || $ligneTiers->lettrage_code !== null) {
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

        $datePaiement = $transaction->date;
        if ((int) $ligneTiers->transaction_id !== (int) $transaction->id) {
            $datePaiement = $ligneTiers->transaction()->firstOrFail()->date;
        }

        $this->ecritureGenerator->pourReglement(
            t1: $transaction,
            mode: $mode,
            compteTresorerie: $compteTresorerie,
            datePaiement: $datePaiement,
        );
    }

    /**
     * Marque une transaction comme réglée via le service de poste tiers daté.
     *
     * @deprecated Les interfaces utilisateur doivent ouvrir PosteTiersReglementModal.
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

        $ligneTiers = $this->posteReporteResolver->dernierePourTransaction($transaction);
        if ($ligneTiers === null) {
            $this->marquerLegacyCommeReglee($transaction, $mode, $compteId);

            return;
        }

        $modeEffectif = $transaction->mode_paiement ?? $mode;
        if ($modeEffectif === null) {
            return;
        }

        $compteIdEffectif = $transaction->compte_id;
        if ($transaction->mode_paiement === null && $mode !== null && $compteId !== null) {
            $compteIdEffectif = $compteId;
        }
        $transactionId = (int) $transaction->id;
        $transactionUpdate = null;
        if ($transaction->mode_paiement === null && $mode !== null) {
            $transactionUpdate = ['mode_paiement' => $mode->value];
            if ($compteId !== null) {
                $transactionUpdate['compte_id'] = $compteId;
            }
        }

        $datePaiement = CarbonImmutable::parse($transaction->date->toDateString());
        if ((int) $ligneTiers->transaction_id !== (int) $transaction->id) {
            $transactionActive = $ligneTiers->transaction()->firstOrFail();
            $datePaiement = CarbonImmutable::parse($transactionActive->date->toDateString());
        }

        $exercice = $this->exerciceService->anneeForDate($datePaiement);
        $poste = $this->postesOuverts->pourTransaction($transaction, $exercice);
        if ($poste === null) {
            app(EtatReglementResolver::class)->syncer($transaction->fresh());

            return;
        }

        DB::transaction(function () use (
            $modeEffectif,
            $compteIdEffectif,
            $datePaiement,
            $exercice,
            $poste,
            $transactionId,
            $transactionUpdate,
        ): void {
            $this->posteTiersReglementService->regler(new PosteTiersReglementData(
                ligneId: $poste->ligneActionId,
                montantCentimes: $poste->soldeCentimes,
                date: $datePaiement,
                mode: $modeEffectif,
                compteBancaireId: $compteIdEffectif !== null ? (int) $compteIdEffectif : null,
                exercice: $exercice,
            ));

            if ($transactionUpdate !== null) {
                Transaction::query()
                    ->whereKey($transactionId)
                    ->update($transactionUpdate);
            }
        }, 3);
    }

    private function marquerLegacyCommeReglee(
        Transaction $transaction,
        ?ModePaiement $mode,
        ?int $compteId,
    ): void {
        $transactionId = (int) $transaction->id;
        $updateData = ['statut_reglement' => StatutReglement::Recu->value];
        if ($transaction->mode_paiement === null && $mode !== null) {
            $updateData['mode_paiement'] = $mode->value;
            if ($compteId !== null) {
                $updateData['compte_id'] = $compteId;
            }
        }

        DB::transaction(function () use ($transactionId, $updateData): void {
            Transaction::query()
                ->whereKey($transactionId)
                ->update($updateData);

            app(EtatReglementResolver::class)->syncer(
                Transaction::findOrFail($transactionId)
            );
        }, 3);
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

        $ligne411T1 = $this->posteReporteResolver->dernierePourTransaction($t1, exigerTiers: false);

        if ($ligne411T1 === null
            || (int) $ligne411T1->compte_id !== (int) $compte411->id
            || $ligne411T1->lettrage_code === null) {
            return null; // Pas encore lettré → pas de T2
        }

        // Chercher la ligne 411 partageant le même code sur une AUTRE transaction
        $ligne411T2 = TransactionLigne::where('lettrage_code', $ligne411T1->lettrage_code)
            ->where('compte_id', (int) $compte411->id)
            ->where('transaction_id', '!=', (int) $ligne411T1->transaction_id)
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

        $ligne401T1 = $this->posteReporteResolver->dernierePourTransaction($t1, exigerTiers: false);

        if ($ligne401T1 === null
            || (int) $ligne401T1->compte_id !== (int) $compte401->id
            || $ligne401T1->lettrage_code === null) {
            return null; // Pas encore lettré → pas de T2
        }

        // Chercher la ligne 401 partageant le même code sur une AUTRE transaction
        $ligne401T2 = TransactionLigne::where('lettrage_code', $ligne401T1->lettrage_code)
            ->where('compte_id', (int) $compte401->id)
            ->where('transaction_id', '!=', (int) $ligne401T1->transaction_id)
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
        $ligneTiers = $this->posteReporteResolver->dernierePourTransaction($t1, exigerTiers: false);

        if ($ligneTiers === null || $ligneTiers->lettrage_code === null) {
            return null;
        }

        $ligneT2 = TransactionLigne::where('lettrage_code', $ligneTiers->lettrage_code)
            ->where('compte_id', (int) $ligneTiers->compte_id)
            ->where('transaction_id', '!=', (int) $ligneTiers->transaction_id)
            ->first();

        return $ligneT2 !== null ? Transaction::find($ligneT2->transaction_id) : null;
    }

    /**
     * Enrichit la Transaction créée par comptabiliserSeance avec les écritures partie double.
     *
     * DC-10a — la ligne de ventilation arrive déjà compte-first (compte_id + credit
     * posés à la création par comptabiliserSeance). Cette méthode collecte la
     * ventilation et délègue à EcritureGenerator::pourRecetteACredit(existingTransaction: $tx)
     * pour ajouter la ligne 411 D tiers.
     *
     * Skippe silencieusement si $compte est null (compte invalide, loggué en amont).
     */
    private function enrichirCreancePartieDouble(
        Transaction $tx,
        TransactionLigne $ligne,
        Tiers $tiers,
        Operation $operation,
        Seance $seance,
        ?Compte $compte,
    ): void {
        if ($compte === null) {
            return;
        }

        // --- Ventilation pour EcritureGenerator ---
        $ventilations = [
            [
                'compte' => $compte,
                'montant' => (float) $ligne->montant,
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

        $tx->forceFill(['equilibree' => true])->save();
        PartieDoubleGuard::assertComplete($tx->fresh());
    }
}
