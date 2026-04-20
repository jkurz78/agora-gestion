<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Models\NoteDeFrais;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class NoteDeFraisValidationService
{
    public function __construct(
        private readonly TransactionService $transactionService,
    ) {}

    /**
     * Rejette une note de frais soumise avec un motif obligatoire.
     *
     * @throws ValidationException si le motif est vide
     * @throws DomainException si la NDF n'est pas en statut Soumise
     */
    public function rejeter(NoteDeFrais $ndf, string $motif): void
    {
        $validator = Validator::make(
            ['motif' => $motif],
            ['motif' => ['required', 'string', 'min:1']],
            [
                'motif.required' => 'Le motif est obligatoire.',
                'motif.min' => 'Le motif est obligatoire.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if ($ndf->statut !== StatutNoteDeFrais::Soumise) {
            throw new DomainException(
                sprintf(
                    'Seule une NDF soumise peut être rejetée (statut actuel : %s).',
                    $ndf->statut->label()
                )
            );
        }

        $ndf->update([
            'statut' => StatutNoteDeFrais::Rejetee->value,
            'motif_rejet' => $motif,
        ]);

        Log::info('comptabilite.ndf.rejected', [
            'ndf_id' => $ndf->id,
            'tiers_id' => $ndf->tiers_id,
            'motif' => $motif,
        ]);
    }

    /**
     * Valide une note de frais soumise en créant une Transaction de type Dépense.
     *
     * - Verrou pessimiste sur la NDF.
     * - Délègue la vérification exercice à TransactionService::create.
     * - Copie les PJ de chaque ligne NDF vers le répertoire de la transaction.
     * - Rollback complet si une copie échoue.
     *
     * @throws DomainException si la NDF n'est pas en statut Soumise
     * @throws \RuntimeException si une pièce jointe source est manquante
     */
    public function valider(NoteDeFrais $ndf, ValidationData $data): Transaction
    {
        return DB::transaction(function () use ($ndf, $data): Transaction {
            // Lock pessimiste sur la ligne ciblée pour éviter la double-validation concurrente
            NoteDeFrais::whereKey($ndf->id)->lockForUpdate()->firstOrFail();
            $ndf->refresh();

            // Statut relu depuis la BDD après refresh (bypass accesseur Payee)
            $statutRaw = (string) $ndf->getRawOriginal('statut');
            if ($statutRaw !== StatutNoteDeFrais::Soumise->value) {
                throw new DomainException(
                    sprintf(
                        'Seule une NDF soumise peut être validée (statut actuel : %s).',
                        $ndf->statut->label()
                    )
                );
            }

            // Charger les lignes NDF ordonnées par id (ordre stable 1-based)
            $lignesNdf = $ndf->lignes()->orderBy('id')->get();

            // Calculer le montant total
            $montantTotal = $lignesNdf->sum(fn ($l) => (float) $l->montant);

            // Construire les données transaction
            $txData = [
                'type' => TypeTransaction::Depense->value,
                'date' => $data->date,
                'libelle' => $ndf->libelle,
                'montant_total' => $montantTotal,
                'mode_paiement' => $data->mode_paiement->value,
                'tiers_id' => $ndf->tiers_id,
                'compte_id' => $data->compte_id,
                'statut_reglement' => StatutReglement::EnAttente->value,
                'association_id' => TenantContext::currentId(),
            ];

            // Construire les lignes transaction (libelle NDF → notes transaction)
            $lignesData = $lignesNdf->map(fn ($ligne) => [
                'sous_categorie_id' => $ligne->sous_categorie_id,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'notes' => $ligne->libelle,
                'montant' => (float) $ligne->montant,
            ])->toArray();

            // Créer la transaction (assertOuvert levée ici si exercice clôturé)
            $transaction = $this->transactionService->create($txData, $lignesData);

            // Recharger les lignes de transaction dans l'ordre stable
            $lignesTx = $transaction->lignes()->orderBy('id')->get();

            // Copier les pièces jointes
            $associationId = (int) TenantContext::currentId();

            foreach ($lignesNdf as $index => $ligneNdf) {
                $sourcePath = $ligneNdf->piece_jointe_path;

                if ($sourcePath === null || $sourcePath === '') {
                    continue;
                }

                // Vérification explicite de l'existence du fichier source
                if (! Storage::disk('local')->exists($sourcePath)) {
                    throw new \RuntimeException(
                        "La pièce jointe source est manquante : {$sourcePath}"
                    );
                }

                $ext = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'bin';
                $n = $index + 1; // 1-based
                $slug = Str::slug($ligneNdf->libelle ?? 'justif') ?: 'justif';
                $destPath = "associations/{$associationId}/transactions/{$transaction->id}/ligne-{$n}-{$slug}.{$ext}";

                $copied = Storage::disk('local')->copy($sourcePath, $destPath);

                if (! $copied) {
                    throw new \RuntimeException(
                        "Impossible de copier la pièce jointe : {$sourcePath} → {$destPath}"
                    );
                }

                // Mettre à jour la ligne transaction correspondante
                $lignesTx[$index]->update(['piece_jointe_path' => $destPath]);
            }

            // Valider la NDF
            $ndf->update([
                'statut' => StatutNoteDeFrais::Validee->value,
                'transaction_id' => $transaction->id,
                'validee_at' => now(),
            ]);

            Log::info('comptabilite.ndf.validated', [
                'ndf_id' => $ndf->id,
                'transaction_id' => $transaction->id,
                'montant_total' => $montantTotal,
                'valide_par' => auth()->id(),
            ]);

            return $transaction;
        });
    }
}
