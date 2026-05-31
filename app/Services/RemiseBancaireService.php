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
            if (! empty($transactionIds)) {
                Transaction::whereIn('id', $transactionIds)
                    ->update([
                        'remise_id' => $remise->id,
                        'statut_reglement' => StatutReglement::Recu->value,
                    ]);
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
        if ($this->queryT4($remise->id)->exists()) {
            throw new \RuntimeException(
                'Cette remise est déjà comptabilisée. Utilisez modifier() pour ajuster la sélection.'
            );
        }

        DB::transaction(function () use ($remise, $transactionIds): void {
            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            $index = 0;

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
            }

            // --- Partie double : générer la T4 de remise ---
            $this->recreerT4($remise, $transactionIds);
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
            // Exclude T4 (which has reference=null) from the transactions to remove.
            // After comptabiliser(), all T1 sources have a non-null reference, only T4 has null.
            $aRetirer = Transaction::where('remise_id', $remise->id)
                ->whereNotIn('id', $transactionIds)
                ->whereNotNull('reference')
                ->get();

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
            }

            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            // Fix Important-1 : exclure la T4 (reference IS NULL) du count pour éviter
            // un décalage d'index. Les T1 sources ont toutes une reference non-null après
            // comptabiliser() ; la T4 a reference = null et sera supprimée par
            // supprimerT4SiExiste() ligne ~170.
            $index = Transaction::where('remise_id', $remise->id)->whereNotNull('reference')->count();

            foreach (Transaction::whereIn('id', $transactionIds)->whereNull('reference')->get() as $tx) {
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
            }

            // --- Partie double : supprimer l'ancienne T4 et en recréer une nouvelle ---
            $this->supprimerT4SiExiste($remise);
            $this->recreerT4($remise, $transactionIds);
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

            // --- Legacy : réinitialiser les tx sources ---
            Transaction::where('remise_id', $remise->id)->update([
                'remise_id' => null,
                'statut_reglement' => StatutReglement::EnAttente->value,
                'reference' => null,
            ]);
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
        // Idempotence guard : T4 déjà construite (equilibree=true) → no-op
        if ($this->queryT4($remise->id)->exists()) {
            return;
        }

        // Trouver les transactions sources : ont une reference (≠ T4 qui a reference=null)
        $sourceIds = Transaction::where('remise_id', $remise->id)
            ->whereNotNull('reference')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (empty($sourceIds)) {
            return; // Rien à convertir
        }

        DB::transaction(function () use ($remise, $sourceIds): void {
            // Phase 2 — construire la T4
            $this->recreerT4($remise, $sourceIds);

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
                $t4 = $this->queryT4($remise->id)->first();

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
     * Critère : (remise_id = X, reference IS NULL, equilibree = true).
     * Repose sur l'invariant que les T1 sources ont TOUJOURS une `reference` non-null
     * après assignation par comptabiliser() ou modifier(). La T4 elle, n'a pas de
     * reference (numérotation legacy non applicable aux écritures partie double).
     *
     * @return Builder<Transaction>
     */
    private function queryT4(int $remiseId): Builder
    {
        return Transaction::where('remise_id', $remiseId)
            ->whereNull('reference')
            ->where('equilibree', true);
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
        $t4 = $this->queryT4($remise->id)->first();

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

            // Chercher la ligne portage (5112 ou 530) sur cette transaction
            $lignePortage = TransactionLigne::where('transaction_id', $tx->id)
                ->where('compte_id', $comptePortage->id)
                ->whereNull('lettrage_code') // Non encore lettrée
                ->whereNull('tiers_id')      // École 411 systématique : sans tiers sur 5x
                ->where('debit', '>', 0)     // Ligne débit (le portage reçoit en débit)
                ->first();

            if ($lignePortage === null) {
                Log::warning('[PartieDouble][RemiseBancaireService] — skip source : aucune ligne portage '.$numeroComptePortage.' trouvée sur transaction', [
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
