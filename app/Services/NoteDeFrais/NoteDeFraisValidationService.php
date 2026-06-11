<?php

declare(strict_types=1);

namespace App\Services\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Enums\StatutReglement;
use App\Enums\TypeTransaction;
use App\Enums\UsageComptable;
use App\Models\NoteDeFrais;
use App\Models\SousCategorie;
use App\Models\Transaction;
use App\Services\Compta\EcritureGenerator;
use App\Services\Compta\EtatReglementResolver;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
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
        private readonly LigneTypeRegistry $ligneTypeRegistry,
        private readonly EcritureGenerator $ecritureGenerator,
        private readonly EtatReglementResolver $etatReglementResolver,
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
     * Valide une note de frais soumise en créant une Transaction de type Dépense (statut EnAttente).
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
            // Garde tenant explicite — fail-fast avant d'acquérir le verrou DB
            if ((int) $ndf->association_id !== (int) TenantContext::currentId()) {
                throw new DomainException('Cette NDF appartient à un autre tenant.');
            }

            // Lock pessimiste sur la ligne ciblée pour éviter la double-validation concurrente
            NoteDeFrais::whereKey($ndf->id)->lockForUpdate()->firstOrFail();
            $ndf->refresh();

            $this->assertStatutSoumise($ndf);

            $transaction = $this->createTransactionDepenseFromNdf($ndf, $data, StatutReglement::EnAttente);

            // Valider la NDF
            $ndf->update([
                'statut' => StatutNoteDeFrais::Validee->value,
                'transaction_id' => $transaction->id,
                'validee_at' => now(),
            ]);

            Log::info('comptabilite.ndf.validated', [
                'ndf_id' => $ndf->id,
                'transaction_id' => $transaction->id,
                'montant_total' => $transaction->montant_total,
                'valide_par' => auth()->id(),
            ]);

            return $transaction;
        });
    }

    /**
     * Valide une note de frais par abandon de créance.
     *
     * Crée deux transactions qui se neutralisent logiquement :
     * 1. Une Transaction Dépense (réglée) portant les lignes NDF.
     * 2. Une Transaction Don/Recette (réglée) du même montant sur la sous-catégorie
     *    désignée pour l'usage AbandonCreance.
     *
     * Les deux transactions ont statut_reglement = Recu → n'apparaissent pas dans
     * les listes "à régler".
     *
     * @param  string  $dateDon  Date ISO Y-m-d du don constaté
     * @return Transaction La Transaction Don (recette)
     *
     * @throws DomainException si la NDF n'est pas Soumise
     * @throws DomainException si aucune sous-catégorie AbandonCreance n'est configurée
     * @throws DomainException si plusieurs sous-catégories AbandonCreance sont configurées
     */
    public function validerAvecAbandonCreance(
        NoteDeFrais $ndf,
        ValidationData $data,
        string $dateDon,
    ): Transaction {
        return DB::transaction(function () use ($ndf, $data, $dateDon): Transaction {
            // Garde tenant explicite — fail-fast avant d'acquérir le verrou DB
            if ((int) $ndf->association_id !== (int) TenantContext::currentId()) {
                throw new DomainException('Cette NDF appartient à un autre tenant.');
            }

            // Lock pessimiste sur la ligne ciblée
            NoteDeFrais::whereKey($ndf->id)->lockForUpdate()->firstOrFail();
            $ndf->refresh();

            $this->assertStatutSoumise($ndf);

            // Résoudre la sous-catégorie AbandonCreance (cardinalité mono)
            $sousCatsAbandon = $ndf->association->sousCategoriesFor(UsageComptable::AbandonCreance);

            if ($sousCatsAbandon->count() === 0) {
                throw new DomainException(
                    "Aucune sous-categorie n'est designee pour l'usage 'Abandon de creance'. "
                    .'Configure-la dans Parametres -> Comptabilite -> Usages.'
                );
            }

            if ($sousCatsAbandon->count() > 1) {
                throw new DomainException(
                    "Plusieurs sous-categories designees pour 'Abandon de creance' — cas anormal."
                );
            }

            $sousCatAbandon = $sousCatsAbandon->first();

            if (config('compta.use_partie_double')) {
                [$txDepense, $txDon] = $this->abandonCreancePd($ndf, $data, $dateDon, $sousCatAbandon);
            } else {
                [$txDepense, $txDon] = $this->abandonCreanceLegacy($ndf, $data, $dateDon, $sousCatAbandon);
            }

            // Mettre à jour la NDF
            $ndf->update([
                'statut' => StatutNoteDeFrais::DonParAbandonCreances->value,
                'transaction_id' => $txDepense->id,
                'don_transaction_id' => $txDon->id,
                'validee_at' => now(),
            ]);

            Log::info('comptabilite.ndf.abandon-creance-constate', [
                'ndf_id' => (int) $ndf->id,
                'tiers_id' => (int) $ndf->tiers_id,
                'transaction_depense_id' => (int) $txDepense->id,
                'transaction_don_id' => (int) $txDon->id,
                'montant' => (float) $txDepense->montant_total,
                'date_don' => $dateDon,
                'valide_par' => auth()->id(),
            ]);

            return $txDon;
        });
    }

    // ---------------------------------------------------------------------------
    // Méthodes privées
    // ---------------------------------------------------------------------------

    /**
     * Vérifie que la NDF est en statut Soumise (relu depuis la BDD après refresh).
     *
     * @throws DomainException
     */
    private function assertStatutSoumise(NoteDeFrais $ndf): void
    {
        $statutRaw = (string) $ndf->getRawOriginal('statut');
        if ($statutRaw !== StatutNoteDeFrais::Soumise->value) {
            throw new DomainException(
                sprintf(
                    'Seule une NDF soumise peut être validée (statut actuel : %s).',
                    $ndf->statut->label()
                )
            );
        }
    }

    /**
     * Crée une Transaction Dépense à partir des lignes d'une NDF et copie les PJ.
     *
     * Le statut de règlement est passé en paramètre pour permettre :
     * - valider()                      → StatutReglement::EnAttente
     * - validerAvecAbandonCreance()     → StatutReglement::Recu
     *
     * Précondition : la NDF doit déjà avoir été lockée et rafraîchie par l'appelant.
     *
     * @throws \RuntimeException si une pièce jointe source est manquante
     */
    private function createTransactionDepenseFromNdf(
        NoteDeFrais $ndf,
        ValidationData $data,
        StatutReglement $statutReglement,
    ): Transaction {
        // Charger les lignes NDF ordonnées par id (ordre stable 1-based)
        $lignesNdf = $ndf->lignes()->orderBy('id')->get();

        // Calculer le montant total
        $montantTotal = $lignesNdf->sum(fn ($l) => (float) $l->montant);

        // Construire les données transaction.
        // mode_paiement est passé à null pour empêcher l'enrichissement PD de créer
        // une T2 (la NDF n'est pas encore payée). Le mode_paiement est stocké sur
        // la Transaction après création via saveQuietly() (pour affichage/référence).
        $txData = [
            'type' => TypeTransaction::Depense->value,
            'date' => $data->date,
            'libelle' => $ndf->libelle,
            'reference' => sprintf('NDF #%d — %s', (int) $ndf->id, $ndf->date->format('d/m/Y')),
            'montant_total' => $montantTotal,
            'mode_paiement' => null,
            'tiers_id' => $ndf->tiers_id,
            'compte_id' => $data->compte_id,
            'statut_reglement' => $statutReglement->value,
            'association_id' => TenantContext::currentId(),
        ];

        // Construire les lignes transaction (libelle NDF → notes transaction)
        $lignesData = $lignesNdf->map(function ($ligne) {
            $strategy = $this->ligneTypeRegistry->for($ligne->type);
            $description = $strategy->renderDescription($ligne->metadata ?? []);

            $notes = $description !== ''
                ? ($ligne->libelle ? "{$ligne->libelle} — {$description}" : $description)
                : $ligne->libelle;

            return [
                'sous_categorie_id' => $ligne->sous_categorie_id,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'notes' => $notes,
                'montant' => (float) $ligne->montant,
            ];
        })->toArray();

        // Créer la transaction (assertOuvert levée ici si exercice clôturé)
        $transaction = $this->transactionService->create($txData, $lignesData);

        // Stocker mode_paiement pour affichage (pas passé à TransactionService
        // pour éviter la création d'un T2 prématuré en mode PD).
        $transaction->forceFill(['mode_paiement' => $data->mode_paiement])->saveQuietly();

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

        return $transaction;
    }

    /**
     * Abandon de créance en mode PD :
     *   T1 Dépense (6xx D / 401 C) + T1 Don (411 D / 7xx C)
     *   + 2 OD compensation via 467 (401→467, 467→411)
     *
     * Le don est une vraie transaction recette (tiers, sous-cat, n° pièce)
     * → visible dans Comptabilité/Dons et historique tiers.
     *
     * @return array{Transaction, Transaction} [$txDepense, $txDon]
     */
    private function abandonCreancePd(
        NoteDeFrais $ndf,
        ValidationData $data,
        string $dateDon,
        SousCategorie $sousCatAbandon,
    ): array {
        // 1. Créer la Transaction Dépense (T1 : 6xx D / 401 C, pas de T2)
        $txDepense = $this->createTransactionDepenseFromNdf($ndf, $data, StatutReglement::EnAttente);

        // 2. Créer la Transaction Don (T1 : 411 D / 7xx C, pas de T2)
        //    Transaction recette standard avec toutes les métadonnées métier.
        $montantTotal = (float) $txDepense->montant_total;

        $txDonData = [
            'type' => TypeTransaction::Recette->value,
            'date' => $dateDon,
            'libelle' => sprintf('Don par abandon de créance — NDF #%d', (int) $ndf->id),
            'reference' => sprintf('NDF #%d — %s', (int) $ndf->id, $ndf->date->format('d/m/Y')),
            'montant_total' => $montantTotal,
            'mode_paiement' => null,
            'tiers_id' => $ndf->tiers_id,
            'compte_id' => null,
            'statut_reglement' => StatutReglement::EnAttente->value,
            'association_id' => TenantContext::currentId(),
        ];

        // Filtrer les lignes métier (exclure les lignes PD pures : 401C, 6xxD)
        $txDonLignes = $txDepense->lignes
            ->filter(fn ($l) => $l->sous_categorie_id !== null && (float) $l->montant > 0)
            ->map(fn ($ligne) => [
                'sous_categorie_id' => (int) $sousCatAbandon->id,
                'operation_id' => $ligne->operation_id,
                'seance' => $ligne->seance,
                'notes' => $ligne->notes,
                'montant' => (float) $ligne->montant,
            ])->all();

        $txDon = $this->transactionService->create($txDonData, $txDonLignes);

        // 3. Créer les 2 OD de compensation (401→467, 467→411 + 3 lettrages)
        $this->ecritureGenerator->pourCompensationAbandon(
            t1Depense: $txDepense,
            t1Don: $txDon,
            dateCompensation: new \DateTimeImmutable($dateDon),
            libelle: sprintf('NDF #%d', (int) $ndf->id),
        );

        // 4. Synchroniser les statuts dérivés (lettrage → Recu)
        $this->etatReglementResolver->syncer($txDepense->fresh());
        $this->etatReglementResolver->syncer($txDon->fresh());

        return [$txDepense, $txDon];
    }

    /**
     * Abandon de créance en mode legacy : 2 transactions (Dépense Recu + Don Recette).
     * Conserve le comportement original pour rétrocompatibilité.
     *
     * @return array{Transaction, Transaction} [$txDepense, $txDon]
     */
    private function abandonCreanceLegacy(
        NoteDeFrais $ndf,
        ValidationData $data,
        string $dateDon,
        SousCategorie $sousCatAbandon,
    ): array {
        // 1. Créer la Transaction Dépense (réglée — pas de flux de tréso à attendre)
        $txDepense = $this->createTransactionDepenseFromNdf($ndf, $data, StatutReglement::Recu);

        // 2. Créer la Transaction Don (recette réglée)
        $montantTotal = (float) $txDepense->montant_total;

        $txDonData = [
            'type' => TypeTransaction::Recette->value,
            'date' => $dateDon,
            'libelle' => sprintf('Don par abandon de créance — NDF #%d', (int) $ndf->id),
            'reference' => sprintf('NDF #%d — %s', (int) $ndf->id, $ndf->date->format('d/m/Y')),
            'montant_total' => $montantTotal,
            'mode_paiement' => $data->mode_paiement?->value,
            'tiers_id' => $ndf->tiers_id,
            'compte_id' => $data->compte_id,
            'statut_reglement' => StatutReglement::Recu->value,
            'association_id' => TenantContext::currentId(),
        ];

        $txDonLignes = $txDepense->lignes->map(fn ($ligne) => [
            'sous_categorie_id' => (int) $sousCatAbandon->id,
            'operation_id' => $ligne->operation_id,
            'seance' => $ligne->seance,
            'notes' => $ligne->notes,
            'montant' => (float) $ligne->montant,
        ])->all();

        $txDon = $this->transactionService->create($txDonData, $txDonLignes);

        return [$txDepense, $txDon];
    }
}
