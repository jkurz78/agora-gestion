<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\StatutFacture;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\FactureLigne;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RemiseBancaireService
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly VirementInterneService $virementInterneService,
    ) {}

    public function creer(array $data): RemiseBancaire
    {
        return DB::transaction(function () use ($data) {
            $numero = (int) RemiseBancaire::withTrashed()->max('numero') + 1;

            $modePaiement = ModePaiement::from($data['mode_paiement']);
            $prefix = $modePaiement === ModePaiement::Cheque ? 'chèques' : 'espèces';
            $libelle = "Remise {$prefix} n°{$numero}";

            return RemiseBancaire::create([
                'numero' => $numero,
                'date' => $data['date'],
                'mode_paiement' => $data['mode_paiement'],
                'compte_cible_id' => $data['compte_cible_id'],
                'libelle' => $libelle,
                'saisi_par' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  array<int>  $reglementIds
     * @param  array<int>  $transactionIds
     */
    public function enregistrerBrouillon(RemiseBancaire $remise, array $reglementIds, array $transactionIds = []): void
    {
        DB::transaction(function () use ($remise, $reglementIds, $transactionIds) {
            // Détacher les règlements qui ne sont plus sélectionnés
            Reglement::where('remise_id', $remise->id)
                ->whereNotIn('id', $reglementIds)
                ->update(['remise_id' => null]);

            // Attacher les nouveaux règlements sélectionnés
            if (count($reglementIds) > 0) {
                Reglement::whereIn('id', $reglementIds)
                    ->where(function ($q) use ($remise) {
                        $q->whereNull('remise_id')
                            ->orWhere('remise_id', $remise->id);
                    })
                    ->update(['remise_id' => $remise->id]);
            }

            // Transactions directes — détacher celles qui ne sont plus sélectionnées
            Transaction::where('remise_id', $remise->id)
                ->whereNull('reglement_id')
                ->whereNotIn('id', $transactionIds)
                ->update(['remise_id' => null]);

            // Transactions directes — rattacher les nouvelles
            if (! empty($transactionIds)) {
                Transaction::whereIn('id', $transactionIds)
                    ->update(['remise_id' => $remise->id]);
            }
        });
    }

    /**
     * @param  array<int>  $reglementIds
     * @param  array<int>  $transactionIds
     */
    public function comptabiliser(RemiseBancaire $remise, array $reglementIds, array $transactionIds = []): void
    {
        if ($remise->virement_id !== null) {
            throw new \RuntimeException('Cette remise est déjà comptabilisée.');
        }

        DB::transaction(function () use ($remise, $reglementIds, $transactionIds) {
            $reglements = Reglement::with(['participant.tiers', 'seance.operation.typeOperation'])
                ->whereIn('id', $reglementIds)
                ->get();

            // Validate all reglements
            foreach ($reglements as $reglement) {
                if ($reglement->remise_id !== null && $reglement->remise_id !== $remise->id) {
                    throw new \RuntimeException("Le règlement #{$reglement->id} est déjà inclus dans une autre remise.");
                }
                if ($reglement->mode_paiement !== $remise->mode_paiement) {
                    throw new \RuntimeException("Le règlement #{$reglement->id} n'a pas le bon mode de paiement.");
                }
            }

            $compteIntermediaire = CompteBancaire::where('est_systeme', true)
                ->where('nom', 'Remises en banque')
                ->firstOrFail();

            $prefix = $remise->referencePrefix();
            $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
            $totalMontant = 0;
            $index = 0;

            foreach ($reglements as $reglement) {
                $index++;
                $participant = $reglement->participant;
                $tiers = $participant->tiers;
                $seance = $reglement->seance;
                $operation = $seance->operation;

                $sousCategorieId = $operation->typeOperation?->sous_categorie_id;
                if ($sousCategorieId === null) {
                    throw new \RuntimeException(
                        "L'opération \"{$operation->nom}\" n'a pas de sous-catégorie définie (type opération manquant ou sans sous-catégorie)."
                    );
                }

                $indexPadded = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";
                $libelle = "Règlement {$tiers->displayName()} - {$operation->nom} S{$seance->numero}";

                $this->transactionService->create([
                    'type' => TypeTransaction::Recette->value,
                    'date' => $remise->date->format('Y-m-d'),
                    'libelle' => $libelle,
                    'montant_total' => $reglement->montant_prevu,
                    'mode_paiement' => $remise->mode_paiement->value,
                    'tiers_id' => $tiers->id,
                    'reference' => $reference,
                    'compte_id' => $compteIntermediaire->id,
                    'remise_id' => $remise->id,
                    'reglement_id' => $reglement->id,
                    'pointe' => true,
                ], [
                    [
                        'sous_categorie_id' => $sousCategorieId,
                        'operation_id' => $operation->id,
                        'seance' => $seance->numero,
                        'montant' => $reglement->montant_prevu,
                        'notes' => null,
                    ],
                ]);

                $reglement->update(['remise_id' => $remise->id]);
                $totalMontant += (float) $reglement->montant_prevu;
            }

            // ── Transactions directes (nouveau flux, déplace sans créer) ──
            foreach ($transactionIds as $transactionId) {
                $transaction = Transaction::findOrFail($transactionId);

                if ($transaction->reglement_id !== null) {
                    throw new \RuntimeException("La transaction #{$transactionId} est liée à un règlement.");
                }

                $index++;
                $indexPadded = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";

                $transaction->update([
                    'compte_origine_id' => $transaction->compte_id,
                    'compte_id' => $compteIntermediaire->id,
                    'remise_id' => $remise->id,
                    'reference' => $reference,
                    'pointe' => true,
                ]);

                $totalMontant += (float) $transaction->montant_total;
            }

            // Create the virement
            $virementReference = "{$prefix}-{$numeroPadded}";
            $virement = $this->virementInterneService->create([
                'date' => $remise->date->format('Y-m-d'),
                'montant' => $totalMontant,
                'compte_source_id' => $compteIntermediaire->id,
                'compte_destination_id' => $remise->compte_cible_id,
                'reference' => $virementReference,
                'notes' => $remise->libelle,
            ]);

            $remise->update(['virement_id' => $virement->id]);
        });
    }

    /**
     * @param  array<int>  $reglementIds
     * @param  array<int>  $transactionIds
     */
    public function modifier(RemiseBancaire $remise, array $reglementIds, array $transactionIds = []): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (count($reglementIds) === 0 && count($transactionIds) === 0) {
            $this->supprimer($remise);

            return;
        }

        DB::transaction(function () use ($remise, $reglementIds, $transactionIds) {
            // Déterminer les règlements actuels via les TRANSACTIONS (pas via Reglement.remise_id
            // qui peut avoir été nettoyé par enregistrerBrouillon)
            $currentReglementIds = Transaction::where('remise_id', $remise->id)
                ->whereNotNull('reglement_id')
                ->pluck('reglement_id')
                ->toArray();
            $toRemove = array_diff($currentReglementIds, $reglementIds);
            $toAdd = array_diff($reglementIds, $currentReglementIds);

            // Remove reglements — bulk delete transactions, lignes, and affectations
            if (count($toRemove) > 0) {
                // Vérifier qu'aucune transaction retirée n'est liée à une facture validée ou annulée
                $txFacturees = Transaction::where('remise_id', $remise->id)
                    ->whereIn('reglement_id', $toRemove)
                    ->whereHas('factures', fn ($q) => $q->where('statut', '!=', 'brouillon'))
                    ->count();

                if ($txFacturees > 0) {
                    throw new \RuntimeException('Impossible de retirer des règlements dont les transactions sont liées à des factures.');
                }

                Reglement::whereIn('id', $toRemove)->update(['remise_id' => null]);

                $txToRemove = Transaction::where('remise_id', $remise->id)
                    ->whereIn('reglement_id', $toRemove)
                    ->pluck('id');

                if ($txToRemove->isNotEmpty()) {
                    // Nettoyer les factures brouillon avant suppression
                    $this->nettoyerFacturesBrouillon($txToRemove);

                    $ligneIds = TransactionLigne::whereIn('transaction_id', $txToRemove)->pluck('id');
                    if ($ligneIds->isNotEmpty()) {
                        TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                        TransactionLigne::whereIn('id', $ligneIds)->delete();
                    }
                    Transaction::whereIn('id', $txToRemove)->forceDelete();
                }
            }

            // ── Transactions directes retirées : restaurer à leur compte d'origine ──
            $txDirectesActuelles = Transaction::where('remise_id', $remise->id)
                ->whereNull('reglement_id')
                ->pluck('id')
                ->all();

            $txARestorer = array_diff($txDirectesActuelles, $transactionIds);
            if (! empty($txARestorer)) {
                foreach (Transaction::whereIn('id', $txARestorer)->get() as $tx) {
                    $tx->update([
                        'compte_id' => $tx->compte_origine_id,
                        'compte_origine_id' => null,
                        'remise_id' => null,
                        'reference' => null,
                        'pointe' => false,
                    ]);
                }
            }

            // Shared variables for both "add reglements" and "add transactions" blocks
            $compteIntermediaire = null;
            $prefix = null;
            $numeroPadded = null;
            $index = null;

            // Add new reglements
            if (count($toAdd) > 0) {
                $newReglements = Reglement::with(['participant.tiers', 'seance.operation.typeOperation'])
                    ->whereIn('id', $toAdd)
                    ->get();

                foreach ($newReglements as $reglement) {
                    if ($reglement->remise_id !== null) {
                        throw new \RuntimeException("Le règlement #{$reglement->id} est déjà inclus dans une autre remise.");
                    }
                    if ($reglement->mode_paiement !== $remise->mode_paiement) {
                        throw new \RuntimeException("Le règlement #{$reglement->id} n'a pas le bon mode de paiement.");
                    }
                }

                $compteIntermediaire = CompteBancaire::where('est_systeme', true)
                    ->where('nom', 'Remises en banque')
                    ->firstOrFail();

                $prefix = $remise->referencePrefix();
                $numeroPadded = str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);

                // Current max index
                $index = Transaction::where('remise_id', $remise->id)->count();

                foreach ($newReglements as $reglement) {
                    $index++;
                    $participant = $reglement->participant;
                    $tiers = $participant->tiers;
                    $seance = $reglement->seance;
                    $operation = $seance->operation;

                    $sousCategorieId = $operation->typeOperation?->sous_categorie_id;
                    if ($sousCategorieId === null) {
                        throw new \RuntimeException(
                            "L'opération \"{$operation->nom}\" n'a pas de sous-catégorie définie (type opération manquant ou sans sous-catégorie)."
                        );
                    }

                    $indexPadded = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                    $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";
                    $libelle = "Règlement {$tiers->displayName()} - {$operation->nom} S{$seance->numero}";

                    $this->transactionService->create([
                        'type' => TypeTransaction::Recette->value,
                        'date' => $remise->date->format('Y-m-d'),
                        'libelle' => $libelle,
                        'montant_total' => $reglement->montant_prevu,
                        'mode_paiement' => $remise->mode_paiement->value,
                        'tiers_id' => $tiers->id,
                        'reference' => $reference,
                        'compte_id' => $compteIntermediaire->id,
                        'remise_id' => $remise->id,
                        'reglement_id' => $reglement->id,
                        'pointe' => true,
                    ], [
                        [
                            'sous_categorie_id' => $sousCategorieId,
                            'operation_id' => $operation->id,
                            'seance' => $seance->numero,
                            'montant' => $reglement->montant_prevu,
                            'notes' => null,
                        ],
                    ]);

                    $reglement->update(['remise_id' => $remise->id]);
                }
            }

            // ── Nouvelles transactions directes : déplacer ──
            $txDirectesNouvelles = array_diff($transactionIds, $txDirectesActuelles);
            if (! empty($txDirectesNouvelles)) {
                $compteIntermediaire = $compteIntermediaire ?? CompteBancaire::where('est_systeme', true)
                    ->where('nom', 'Remises en banque')->firstOrFail();
                $prefix = $prefix ?? $remise->referencePrefix();
                $numeroPadded = $numeroPadded ?? str_pad((string) $remise->numero, 5, '0', STR_PAD_LEFT);
                $index = $index ?? Transaction::where('remise_id', $remise->id)->count();

                foreach ($txDirectesNouvelles as $txId) {
                    $tx = Transaction::findOrFail($txId);
                    $index++;
                    $indexPadded = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                    $reference = "{$prefix}-{$numeroPadded}-{$indexPadded}";

                    $tx->update([
                        'compte_origine_id' => $tx->compte_id,
                        'compte_id' => $compteIntermediaire->id,
                        'remise_id' => $remise->id,
                        'reference' => $reference,
                        'pointe' => true,
                    ]);
                }
            }

            // Update virement montant — includes both reglements and direct transactions
            $newTotalReglements = (float) Reglement::where('remise_id', $remise->id)->sum('montant_prevu');
            $newTotalTransactions = (float) Transaction::where('remise_id', $remise->id)
                ->whereNull('reglement_id')
                ->sum('montant_total');
            $newTotal = $newTotalReglements + $newTotalTransactions;

            $this->virementInterneService->update($remise->virement, [
                'date' => $remise->date->format('Y-m-d'),
                'montant' => $newTotal,
            ]);
        });
    }

    public function supprimer(RemiseBancaire $remise): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        // Vérifier qu'aucune transaction n'est liée à une facture validée ou annulée
        $txFacturees = Transaction::where('remise_id', $remise->id)
            ->whereHas('factures', fn ($q) => $q->where('statut', '!=', 'brouillon'))
            ->count();

        if ($txFacturees > 0) {
            throw new \RuntimeException('Impossible de supprimer : des transactions de cette remise sont liées à des factures.');
        }

        DB::transaction(function () use ($remise) {
            // Free all reglements
            Reglement::where('remise_id', $remise->id)->update(['remise_id' => null]);

            // Restore direct transactions (moved ones) to their original account
            $txDirectes = Transaction::where('remise_id', $remise->id)
                ->whereNull('reglement_id')
                ->whereNotNull('compte_origine_id')
                ->get();

            foreach ($txDirectes as $tx) {
                $tx->update([
                    'compte_id' => $tx->compte_origine_id,
                    'compte_origine_id' => null,
                    'remise_id' => null,
                    'reference' => null,
                    'pointe' => false,
                ]);
            }

            // Delete transactions created for reglements
            $txReglementIds = Transaction::where('remise_id', $remise->id)
                ->whereNotNull('reglement_id')
                ->pluck('id');

            if ($txReglementIds->isNotEmpty()) {
                // Nettoyer les factures brouillon avant suppression
                $this->nettoyerFacturesBrouillon($txReglementIds);

                $ligneIds = TransactionLigne::whereIn('transaction_id', $txReglementIds)->pluck('id');
                if ($ligneIds->isNotEmpty()) {
                    TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                    TransactionLigne::whereIn('id', $ligneIds)->delete();
                }
                Transaction::whereIn('id', $txReglementIds)->delete();
            }

            // Soft-delete virement
            if ($remise->virement_id !== null) {
                $remise->virement->delete();
            }

            // Soft-delete remise
            $remise->delete();
        });
    }

    /**
     * Nettoyer les factures brouillon liées aux transactions qui vont être supprimées.
     * Détache les transactions du pivot et supprime les FactureLigne orphelines.
     *
     * @param  Collection<int, int>  $transactionIds
     */
    private function nettoyerFacturesBrouillon(Collection $transactionIds): void
    {
        if ($transactionIds->isEmpty()) {
            return;
        }

        $ligneIds = TransactionLigne::whereIn('transaction_id', $transactionIds)->pluck('id');

        // Supprimer les FactureLigne de brouillons qui pointent vers ces transaction_lignes
        if ($ligneIds->isNotEmpty()) {
            FactureLigne::whereIn('transaction_ligne_id', $ligneIds)
                ->whereHas('facture', fn ($q) => $q->where('statut', StatutFacture::Brouillon))
                ->delete();
        }

        // Détacher les transactions du pivot facture_transaction pour les brouillons
        foreach ($transactionIds as $txId) {
            DB::table('facture_transaction')
                ->where('transaction_id', $txId)
                ->whereIn('facture_id', function ($sub) {
                    $sub->select('id')->from('factures')->where('statut', StatutFacture::Brouillon);
                })
                ->delete();
        }
    }
}
