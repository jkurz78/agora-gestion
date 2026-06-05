<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\StatutReglement;
use App\Models\Compte;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\Compta\LettrageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RemiseBancaireService
{
    public function __construct(
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly LettrageService $lettrageService,
        private readonly ReglementOperationService $reglementService,
        private readonly EtatReglementResolver $etatReglementResolver,
    ) {}

    public function creer(array $data): RemiseBancaire
    {
        return DB::transaction(function () use ($data): RemiseBancaire {
            $numero = (int) RemiseBancaire::withTrashed()->max('numero') + 1;
            $modePaiement = ModePaiement::from($data['mode_paiement']);
            $prefix = $modePaiement === ModePaiement::Cheque ? 'chèques' : 'espèces';

            return RemiseBancaire::create([
                'numero' => $numero,
                'date' => $data['date'],
                'mode_paiement' => $data['mode_paiement'],
                'compte_cible_id' => $data['compte_cible_id'],
                'libelle' => "Remise {$prefix} n°{$numero}",
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function enregistrerBrouillon(RemiseBancaire $remise, array $transactionIds): void
    {
        DB::transaction(function () use ($remise, $transactionIds): void {
            // Retirer les transactions déselectionnées → repasser en attente
            Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->update([
                    'remise_id' => null,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reference' => null,
                ]);

            // Ajouter les transactions sélectionnées → reçues (prêtes pour dépôt)
            // Legacy : Recu. PD : le syncer dérive EnMain (5112 non lettré = chèque en main).
            if (! empty($transactionIds)) {
                foreach ($transactionIds as $txId) {
                    Transaction::where('id', $txId)->update([
                        'remise_id' => $remise->id,
                        'statut_reglement' => StatutReglement::Recu->value,
                    ]);

                    $tx = Transaction::find($txId);
                    if ($tx !== null) {
                        app(EtatReglementResolver::class)->syncer($tx->fresh());
                    }
                }
            }
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function comptabiliser(RemiseBancaire $remise, array $transactionIds): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        // Garde idempotence : si T4 déjà créée, l'utilisateur doit passer par modifier()
        if ($this->queryT4($remise)->exists()) {
            throw new \RuntimeException(
                'Cette remise est déjà comptabilisée. Utilisez modifier() pour ajuster la sélection.'
            );
        }

        DB::transaction(function () use ($remise, $transactionIds): void {
            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            $index = 0;

            // Précharger le compte 411 une seule fois pour éviter N+1
            $compte411 = Compte::ofNumero('411');

            /** @var list<int> $processedIds */
            $processedIds = [];

            foreach ($transactionIds as $txId) {
                $tx = Transaction::findOrFail($txId);

                if ($tx->mode_paiement !== $remise->mode_paiement) {
                    throw new \RuntimeException(
                        "La transaction #{$txId} n'a pas le bon mode de paiement ({$tx->mode_paiement?->label()})."
                    );
                }

                $index++;
                $reference = sprintf(
                    '%s-%s-%s',
                    $prefix,
                    $numeroPadded,
                    str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                );

                $tx->update([
                    'remise_id' => $remise->id,
                    'statut_reglement' => StatutReglement::Recu->value,
                    'reference' => $reference,
                ]);

                // Fix C : garantir que la ligne de portage 5112/530 existe avant recreerT4.
                // Idempotent : no-op si la ligne 411 est déjà lettrée (T2 déjà générée).
                $this->reglementService->encaisserSiNonEncaisse($tx->fresh(), $compte411);

                $processedIds[] = (int) $txId;
            }

            // --- Partie double : générer la T4 de remise ---
            $this->recreerT4($remise, $transactionIds);

            // État explicite : marquer la remise comme comptabilisée
            $remise->update(['comptabilisee_at' => now()]);

            // Chantier 4 — statut dérivé : syncer chaque source après création T4 et lettrage
            // (le resolver voit maintenant la ligne 5112/530 lettrée + T4 avec 512X).
            foreach ($processedIds as $txId) {
                $source = Transaction::find($txId);
                if ($source !== null) {
                    $this->etatReglementResolver->syncer($source);
                }
            }
        });
    }

    /**
     * @param  array<int>  $transactionIds
     */
    public function modifier(RemiseBancaire $remise, array $transactionIds): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (empty($transactionIds)) {
            $this->supprimer($remise);

            return;
        }

        DB::transaction(function () use ($remise, $transactionIds): void {
            // Identifier la T4 par critère structurel (ligne 512X au débit), avant toute
            // suppression. Indépendant de `reference` : des chèques remisés réels en prod
            // ont reference = NULL sur les sources (Finding 2, cutover 2026-05-31).
            $t4Id = $this->queryT4($remise)->value('id');

            // (a) Sources à retirer : toutes les tx de la remise hors périmètre gardé,
            // en excluant la T4 par son id structurel (et non par reference IS NULL).
            $aRetirer = Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->get();

            // Collecter les IDs retirés pour le passage syncer (passe 2 après recreerT4)
            /** @var list<int> $retiresIds */
            $retiresIds = [];

            foreach ($aRetirer as $tx) {
                if ($tx->factures()->where('statut', '!=', StatutFacture::Brouillon->value)->exists()) {
                    throw new \RuntimeException(
                        "Impossible de retirer la transaction #{$tx->id} : liée à une facture."
                    );
                }

                $tx->update([
                    'remise_id' => null,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reference' => null,
                ]);

                $retiresIds[] = (int) $tx->id;
            }

            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            // (b) Compter les sources déjà référencées pour calculer le prochain index,
            // en excluant la T4 par son id structurel (et non par reference IS NULL).
            $index = Transaction::where('remise_id', $remise->id)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->count();

            // Précharger le compte 411 une seule fois pour éviter N+1
            $compte411 = Compte::ofNumero('411');

            // (c) Nouvelles sources à référencer : tx sans reference dans le périmètre gardé,
            // en excluant la T4 par son id structurel (défense en profondeur).
            foreach (Transaction::whereIn('id', $transactionIds)
                ->when($t4Id !== null, fn ($q) => $q->where('id', '!=', $t4Id))
                ->whereNull('reference')
                ->get() as $tx) {
                $index++;
                $tx->update([
                    'remise_id' => $remise->id,
                    'statut_reglement' => StatutReglement::Recu->value,
                    'reference' => sprintf(
                        '%s-%s-%s',
                        $prefix,
                        $numeroPadded,
                        str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                    ),
                ]);

                // Fix C : garantir T2 (encaissement) pour les nouvelles sources en attente.
                // Idempotent : no-op si 411 déjà lettrée.
                $this->reglementService->encaisserSiNonEncaisse($tx->fresh(), $compte411);
            }

            // --- Partie double : supprimer l'ancienne T4 et en recréer une nouvelle ---
            $this->supprimerT4SiExiste($remise);
            $this->recreerT4($remise, $transactionIds);

            // --- PD : syncer toutes les tx touchées (retirées + gardées) après recreerT4 ---
            // Les retirées obtiennent EnMain (5112 délettré), les gardées obtiennent Recu
            // (5112 lettrée via nouvelle T4). Legacy : no-op (use_partie_double=false).
            $tousIds = array_unique(array_merge($retiresIds, array_map('intval', $transactionIds)));
            foreach ($tousIds as $id) {
                $s = Transaction::find($id);
                if ($s !== null) {
                    app(EtatReglementResolver::class)->syncer($s->fresh());
                }
            }
        });
    }

    public function supprimer(RemiseBancaire $remise): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (Transaction::where('remise_id', $remise->id)
            ->whereHas('factures', fn ($q) => $q->where('statut', '!=', StatutFacture::Brouillon->value))
            ->exists()) {
            throw new \RuntimeException('Impossible de supprimer : des transactions sont liées à des factures.');
        }

        DB::transaction(function () use ($remise): void {
            // --- Partie double : supprimer T4 + délettrer sources ---
            $this->supprimerT4SiExiste($remise);

            // --- Legacy : réinitialiser les tx sources (passe 1 : mise à jour) ---
            // En legacy : EnAttente. En PD : le syncer dérive EnMain (5112 délettré = chèque en main).
            $sources = Transaction::where('remise_id', $remise->id)->get();

            foreach ($sources as $source) {
                $source->update([
                    'remise_id' => null,
                    'statut_reglement' => StatutReglement::EnAttente->value,
                    'reference' => null,
                ]);
            }

            // --- PD : syncer chaque source après T4 supprimée et EnAttente posé (passe 2) ---
            foreach ($sources as $source) {
                app(EtatReglementResolver::class)->syncer($source->fresh());
            }

            $remise->delete();
        });
    }

    // -------------------------------------------------------------------------
    // Backfill — Point d'entrée public pour la reconstruction des remises (Wave 3)
    // -------------------------------------------------------------------------

    /**
     * Reconstruit la T4 (`512x → 5112`) d'une remise lors du backfill partie double.
     *
     * Phase 2 : génère l'écriture T4 à partir des lignes de portage 5112/530 non-lettrées
     *           déposées sur les sources par la phase 1 (TransactionConverter).
     * Phase 3 : propage le `rapprochement_id` unique des sources sur la T4 (survie rappro).
     *
     * Idempotent : no-op si une T4 valide (equilibree=true, reference null) existe déjà.
     *
     * La garde queryT4 est un check-then-act sans verrou : sûr car le backfill est une
     * commande artisan mono-opérateur exécutée une fois lors du cutover (jamais en
     * concurrence — cf. BackfillPartieDoubleCommand).
     *
     * Appelé par BackfillPartieDoubleCommand::runConversion après la boucle de conversion
     * des transactions individuelles.
     */
    public function reconstruireT4Backfill(RemiseBancaire $remise): void
    {
        // Idempotence guard : T4 déjà construite → no-op
        if ($this->queryT4($remise)->exists()) {
            return;
        }

        // Aucune T4 n'existe encore (garde ci-dessus) : toutes les transactions de la
        // remise sont donc des sources. Volontairement indépendant de `reference` car
        // des chèques remisés réels (prod) ont reference = NULL (Finding 2, cutover 2026-05-31).
        $sourceIds = Transaction::where('remise_id', $remise->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($sourceIds)) {
            return; // Rien à convertir
        }

        DB::transaction(function () use ($remise, $sourceIds): void {
            // Phase 2 — construire la T4
            $this->recreerT4($remise, $sourceIds);

            // Bug 1 — aligner comptabilisee_at : si la T4 a bien été créée, la remise est
            // comptabilisée. Sans ça, une remise backfillée (T4 créé APRÈS la migration
            // comptabilisee_at) reste à NULL et s'affiche à tort « brouillon ».
            if ($this->queryT4($remise)->exists()) {
                $remise->update(['comptabilisee_at' => $remise->date]);
            }

            // Phase 3 — propager le rapprochement_id unique des sources sur la T4
            $rapprochementIds = Transaction::whereIn('id', $sourceIds)
                ->whereNotNull('rapprochement_id')
                ->distinct()
                ->pluck('rapprochement_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->all();

            if (count($rapprochementIds) === 1) {
                // Exactement un rapprochement → le poser sur la T4
                $t4 = $this->queryT4($remise)->first();

                if ($t4 !== null) {
                    $t4->update(['rapprochement_id' => $rapprochementIds[0]]);
                }
            } elseif (count($rapprochementIds) > 1) {
                Log::warning('[PartieDouble][RemiseBancaireService] — rapprochement_id incohérent sur les sources de la remise (plusieurs valeurs distinctes) : propagation non effectuée', [
                    'remise_id' => (int) $remise->id,
                    'rapprochement_ids' => $rapprochementIds,
                ]);
            }
            // Si 0 rapprochement_id → remise non rapprochée, rien à faire
        });
    }

    // -------------------------------------------------------------------------
    // Helpers privés — Identification T4
    // -------------------------------------------------------------------------

    /**
     * Identifie la T4 (transaction de dépôt partie double) d'une remise.
     *
     * Critère structurel : la transaction de la remise qui porte une ligne 512X au
     * débit (le dépôt en banque). Les sources portent leur portage sur 5112/530, jamais
     * sur 512X → critère discriminant et indépendant de `reference` (des chèques remisés
     * réels en prod ont reference = NULL — Finding 2, cutover 2026-05-31).
     *
     * @return Builder<Transaction>
     */
    private function queryT4(RemiseBancaire $remise): Builder
    {
        $compte512X = $this->resoudreCompte512X($remise);

        // Sans compte 512X (tenant sans schéma PD), aucune T4 ne peut exister.
        if ($compte512X === null) {
            return Transaction::whereRaw('1 = 0');
        }

        // La T4 est la transaction de la remise qui porte une ligne 512X au débit (le dépôt).
        // Les sources portent leur portage sur 5112/530, jamais sur 512X → critère discriminant.
        // Volontairement indépendant de `reference` : des chèques remisés réels (prod) ont
        // reference = NULL, ce qui faisait matcher les sources par l'ancien critère
        // `reference IS NULL` (Finding 2, cutover 2026-05-31).
        return Transaction::where('remise_id', $remise->id)
            ->whereHas('lignes', fn (Builder $q): Builder => $q
                ->where('compte_id', $compte512X->id)
                ->where('debit', '>', 0));
    }

    /**
     * Résout le compte PCG 512X (bancaire physique) du CompteBancaire cible de la remise.
     * Retourne null si le tenant n'a pas de schéma PD (compte 512X absent).
     */
    private function resoudreCompte512X(RemiseBancaire $remise): ?Compte
    {
        $compteBancaire = $remise->compteCible;

        if ($compteBancaire === null) {
            return null;
        }

        return Compte::where('compte_bancaire_id', $compteBancaire->id)
            ->bancaires()
            ->first();
    }

    // -------------------------------------------------------------------------
    // Helpers privés — T4 lifecycle (créer / supprimer)
    // -------------------------------------------------------------------------

    /**
     * Supprime la T4 de remise si elle existe : délettre les paires 5112/530
     * des lignes sources, supprime les lignes de T4, puis supprime T4.
     *
     * Utilisé par comptabiliser() (delete before recreate), modifier() (idem)
     * et supprimer() (delete only, sans recreate).
     *
     * Appelé à l'intérieur d'une DB::transaction() englobante.
     */
    private function supprimerT4SiExiste(RemiseBancaire $remise): void
    {
        $t4 = $this->queryT4($remise)->first();

        if ($t4 === null) {
            return;
        }

        // Délettrer toutes les lignes portage (5112/530) de T4 qui sont lettrées
        $lignesPortageT4 = TransactionLigne::where('transaction_id', $t4->id)
            ->whereNotNull('lettrage_code')
            ->get();

        foreach ($lignesPortageT4 as $ligne) {
            $this->lettrageService->delettrerParLigne(
                $ligne->fresh(), // Recharger pour avoir lettrage_code à jour
                "Suppression remise bancaire #{$remise->id} — délettrage T4 ligne #{$ligne->id}"
            );
        }

        // Supprimer les lignes de T4
        TransactionLigne::where('transaction_id', $t4->id)->forceDelete();

        // Supprimer T4 (soft-delete suffisant — Transaction::find() retournera null)
        $t4->forceDelete();
    }

    /**
     * Collecte les lignes 5112/530 sources des transactions et appelle
     * EcritureGenerator::pourRemiseBancaire pour créer la T4.
     * Pose ensuite remise_id = $remise->id sur la T4.
     *
     * Si aucune source valide n'est trouvée (toutes legacy), log error et ne crée pas de T4.
     * Si certaines sources sont legacy, skip silencieux + Log::warning pour chacune.
     *
     * Appelé à l'intérieur d'une DB::transaction() englobante.
     *
     * @param  array<int>  $transactionIds
     */
    private function recreerT4(RemiseBancaire $remise, array $transactionIds): void
    {
        $mode = $remise->mode_paiement;

        // Résoudre le compte de portage attendu (5112 pour Chèque, 530 pour Espèces)
        $numeroComptePortage = match ($mode) {
            ModePaiement::Cheque => '5112',
            ModePaiement::Especes => '530',
            default => null,
        };

        if ($numeroComptePortage === null) {
            // Mode non supporté pour la remise partie double (Virement, CB, Prélèvement)
            // EcritureGenerator::pourRemiseBancaire lèvera une exception si on tente —
            // on skip silencieusement ici (cohérence avec la garde dans EcritureGenerator)
            Log::warning('[PartieDouble][RemiseBancaireService] — skip : mode non supporté pour remise partie double', [
                'remise_id' => $remise->id,
                'mode_paiement' => $mode->value,
            ]);

            return;
        }

        // Résoudre le compte portage (nullable : tenant sans schéma PD)
        $comptePortage = Compte::ofNumero($numeroComptePortage);

        if ($comptePortage === null) {
            Log::warning('[PartieDouble][RemiseBancaireService] — skip : compte portage introuvable (tenant sans schéma PD)', [
                'remise_id' => $remise->id,
                'numero_compte_portage' => $numeroComptePortage,
            ]);

            return;
        }

        // Collecter les lignes portage sources valides
        /** @var Collection<int, TransactionLigne> $lignesSources */
        $lignesSources = collect();

        foreach ($transactionIds as $txId) {
            $tx = Transaction::find($txId);

            if ($tx === null) {
                continue;
            }

            // Fix C — subtilité lumped vs T2 séparé :
            // Cas lumped (pourRecetteComptant / backfill) : la ligne portage est sur $tx.
            // Cas séparé (encaisserSiNonEncaisse → pourEncaissementCreance) : la ligne portage
            // est sur le T2 (autre transaction). On cherche d'abord sur $tx, puis sur T2.

            // Chercher la ligne portage (5112 ou 530) sur cette transaction (cas lumped)
            $lignePortage = TransactionLigne::where('transaction_id', $tx->id)
                ->where('compte_id', $comptePortage->id)
                ->whereNull('lettrage_code') // Non encore lettrée
                ->whereNull('tiers_id')      // École 411 systématique : sans tiers sur 5x
                ->where('debit', '>', 0)     // Ligne débit (le portage reçoit en débit)
                ->first();

            // Si non trouvée sur la source, chercher sur le T2 séparé (cas en_attente)
            if ($lignePortage === null) {
                $t2 = $this->reglementService->trouverEncaissementT2($tx);

                if ($t2 !== null) {
                    $lignePortage = TransactionLigne::where('transaction_id', $t2->id)
                        ->where('compte_id', $comptePortage->id)
                        ->whereNull('lettrage_code')
                        ->whereNull('tiers_id')
                        ->where('debit', '>', 0)
                        ->first();
                }
            }

            if ($lignePortage === null) {
                Log::warning('[PartieDouble][RemiseBancaireService] — skip source : aucune ligne portage '.$numeroComptePortage.' trouvée sur transaction ni sur T2', [
                    'remise_id' => $remise->id,
                    'transaction_id' => $txId,
                    'compte_portage' => $numeroComptePortage,
                    'note' => 'Transaction legacy ou non issue du branchement EcritureGenerator Step 21',
                ]);

                continue;
            }

            $lignesSources->push($lignePortage);
        }

        if ($lignesSources->isEmpty()) {
            Log::warning('[PartieDouble][RemiseBancaireService] — aucune source valide : pas de T4 créée', [
                'remise_id' => $remise->id,
                'transaction_ids' => $transactionIds,
            ]);

            return;
        }

        // Créer la T4 via EcritureGenerator
        $t4 = $this->ecritureGenerator->pourRemiseBancaire($remise, $lignesSources);

        // Lier la T4 à la remise (traçabilité : remise_id posé sur T4, sans reference)
        $t4->update(['remise_id' => $remise->id]);
    }
}
