<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ModePaiement;
use App\Enums\TypeTransaction;
use App\Models\CompteBancaire;
use App\Models\Reglement;
use App\Models\RemiseBancaire;
use App\Models\Transaction;
use App\Models\TransactionLigne;
use App\Models\TransactionLigneAffectation;
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

    public function enregistrerBrouillon(RemiseBancaire $remise, array $reglementIds): void
    {
        DB::transaction(function () use ($remise, $reglementIds) {
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
        });
    }

    public function comptabiliser(RemiseBancaire $remise, array $reglementIds): void
    {
        if ($remise->virement_id !== null) {
            throw new \RuntimeException('Cette remise est déjà comptabilisée.');
        }

        DB::transaction(function () use ($remise, $reglementIds) {
            $reglements = Reglement::with(['participant.tiers', 'seance.operation.typeOperation'])
                ->whereIn('id', $reglementIds)
                ->get();

            // Validate all reglements
            foreach ($reglements as $reglement) {
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

    public function modifier(RemiseBancaire $remise, array $reglementIds): void
    {
        if ($remise->isVerrouillee()) {
            throw new \RuntimeException('Cette remise est verrouillée par un rapprochement bancaire.');
        }

        if (count($reglementIds) === 0) {
            $this->supprimer($remise);

            return;
        }

        DB::transaction(function () use ($remise, $reglementIds) {
            $currentReglementIds = Reglement::where('remise_id', $remise->id)->pluck('id')->toArray();
            $toRemove = array_diff($currentReglementIds, $reglementIds);
            $toAdd = array_diff($reglementIds, $currentReglementIds);

            // Remove reglements — bulk delete transactions, lignes, and affectations
            if (count($toRemove) > 0) {
                Reglement::whereIn('id', $toRemove)->update(['remise_id' => null]);

                $txToRemove = Transaction::where('remise_id', $remise->id)
                    ->whereIn('reglement_id', $toRemove)
                    ->pluck('id');

                if ($txToRemove->isNotEmpty()) {
                    $ligneIds = TransactionLigne::whereIn('transaction_id', $txToRemove)->pluck('id');
                    if ($ligneIds->isNotEmpty()) {
                        TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                        TransactionLigne::whereIn('id', $ligneIds)->delete();
                    }
                    Transaction::whereIn('id', $txToRemove)->forceDelete();
                }
            }

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
                $currentCount = Transaction::where('remise_id', $remise->id)->count();
                $index = $currentCount;

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

            // Update virement montant
            $newTotal = (float) Reglement::where('remise_id', $remise->id)->sum('montant_prevu');
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

        DB::transaction(function () use ($remise) {
            // Free all reglements
            Reglement::where('remise_id', $remise->id)->update(['remise_id' => null]);

            // Bulk-delete all transactions, lignes, and affectations
            $txIds = Transaction::where('remise_id', $remise->id)->pluck('id');
            if ($txIds->isNotEmpty()) {
                $ligneIds = TransactionLigne::whereIn('transaction_id', $txIds)->pluck('id');
                if ($ligneIds->isNotEmpty()) {
                    TransactionLigneAffectation::whereIn('transaction_ligne_id', $ligneIds)->delete();
                    TransactionLigne::whereIn('id', $ligneIds)->delete();
                }
                Transaction::whereIn('id', $txIds)->delete();
            }

            // Soft-delete virement
            if ($remise->virement_id !== null) {
                $remise->virement->delete();
            }

            // Soft-delete remise
            $remise->delete();
        });
    }
}
