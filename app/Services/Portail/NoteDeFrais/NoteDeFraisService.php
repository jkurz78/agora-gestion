<?php

declare(strict_types=1);

namespace App\Services\Portail\NoteDeFrais;

use App\Enums\NoteDeFraisLigneType;
use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Tiers;
use App\Services\NoteDeFrais\LigneTypes\LigneTypeRegistry;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class NoteDeFraisService
{
    /**
     * Crée ou met à jour un brouillon de note de frais.
     *
     * @param  array{
     *     id?: int|null,
     *     date: string,
     *     libelle: string,
     *     lignes: list<array{
     *         type?: string,
     *         libelle: string|null,
     *         montant: float|int,
     *         sous_categorie_id: int|null,
     *         operation_id?: int|null,
     *         seance?: int|null,
     *         piece_jointe_path: string|null,
     *         cv_fiscaux?: int|null,
     *         distance_km?: float|int|null,
     *         bareme_eur_km?: float|null,
     *     }>
     * }  $data
     */
    public function saveDraft(Tiers $tiers, array $data): NoteDeFrais
    {
        return DB::transaction(function () use ($tiers, $data): NoteDeFrais {
            $id = isset($data['id']) ? (int) $data['id'] : null;

            if ($id !== null) {
                $ndf = NoteDeFrais::findOrFail($id);

                if ((int) $ndf->tiers_id !== (int) $tiers->id) {
                    throw new DomainException('Cette note de frais n\'appartient pas à ce tiers.');
                }

                $editableStatuts = [StatutNoteDeFrais::Brouillon, StatutNoteDeFrais::Soumise, StatutNoteDeFrais::Rejetee];
                if (! in_array($ndf->statut, $editableStatuts, true)) {
                    throw new DomainException('Seul un brouillon, une NDF soumise ou rejetée peut être modifié(e).');
                }

                $wasNonBrouillon = $ndf->statut !== StatutNoteDeFrais::Brouillon;

                $ndf->update([
                    'date' => $data['date'],
                    'libelle' => $data['libelle'] ?? '',
                ]);

                // Si la NDF n'était pas déjà un brouillon, on remet en brouillon :
                // l'utilisateur doit re-soumettre. Le motif de rejet est effacé.
                if ($wasNonBrouillon) {
                    $ndf->update([
                        'statut' => StatutNoteDeFrais::Brouillon->value,
                        'submitted_at' => null,
                        'motif_rejet' => null,
                    ]);
                }

                // Remplace toutes les lignes existantes
                $ndf->lignes()->delete();
            } else {
                $ndf = NoteDeFrais::create([
                    'association_id' => TenantContext::currentId(),
                    'tiers_id' => $tiers->id,
                    'date' => $data['date'],
                    'libelle' => $data['libelle'] ?? '',
                    'statut' => StatutNoteDeFrais::Brouillon->value,
                    'submitted_at' => null,
                    'validee_at' => null,
                ]);
            }

            $registry = app(LigneTypeRegistry::class);

            foreach ($data['lignes'] as $ligneData) {
                $typeValue = $ligneData['type'] ?? NoteDeFraisLigneType::Standard->value;
                $type = NoteDeFraisLigneType::from($typeValue);
                $strategy = $registry->for($type);

                $montant = $strategy->computeMontant($ligneData);
                $metadata = $strategy->metadata($ligneData);
                $sousCategorieId = $strategy->resolveSousCategorieId(
                    isset($ligneData['sous_categorie_id']) ? (int) $ligneData['sous_categorie_id'] : null
                );

                NoteDeFraisLigne::create([
                    'note_de_frais_id' => $ndf->id,
                    'type' => $type->value,
                    'libelle' => $ligneData['libelle'] ?? null,
                    'montant' => $montant,
                    'metadata' => $metadata !== [] ? $metadata : null,
                    'sous_categorie_id' => $sousCategorieId,
                    'operation_id' => $ligneData['operation_id'] ?? null,
                    'seance' => $ligneData['seance'] ?? null,
                    'piece_jointe_path' => $ligneData['piece_jointe_path'] ?? null,
                ]);
            }

            $ndf->refresh();

            if ($id !== null) {
                Log::info('portail.ndf.updated', [
                    'ndf_id' => $ndf->id,
                    'tiers_id' => $tiers->id,
                ]);
            } else {
                Log::info('portail.ndf.created', [
                    'ndf_id' => $ndf->id,
                    'tiers_id' => $tiers->id,
                    'libelle' => $ndf->libelle,
                ]);
            }

            return $ndf;
        });
    }

    /**
     * Soumet une note de frais après validation des règles métier.
     *
     * @throws ValidationException si les règles métier ne sont pas satisfaites
     * @throws DomainException si la NDF n'est pas en brouillon
     */
    public function submit(NoteDeFrais $ndf): void
    {
        if ($ndf->statut !== StatutNoteDeFrais::Brouillon) {
            throw new DomainException('Seul un brouillon peut être soumis.');
        }

        $lignes = $ndf->lignes()->get();

        // Validation métier via Validator::make avec messages en français
        $validator = Validator::make(
            [
                'date' => $ndf->date?->format('Y-m-d'),
                'libelle' => $ndf->libelle,
                'lignes' => $lignes->toArray(),
            ],
            [
                'date' => ['required', 'date', 'before_or_equal:today'],
                'libelle' => ['required', 'string', 'min:1'],
                'lignes' => ['required', 'array', 'min:1'],
                'lignes.*.montant' => ['required', 'numeric', 'gt:0'],
                'lignes.*.piece_jointe_path' => ['required', 'string', 'min:1'],
            ],
            [
                'date.before_or_equal' => 'La date ne peut pas être dans le futur.',
                'date.required' => 'La date est obligatoire.',
                'libelle.required' => 'Le libellé est obligatoire.',
                'libelle.min' => 'Le libellé est obligatoire.',
                'lignes.required' => 'Au moins une ligne est requise.',
                'lignes.min' => 'Au moins une ligne est requise.',
                'lignes.*.montant.gt' => 'Le montant doit être supérieur à zéro.',
                'lignes.*.montant.required' => 'Le montant est obligatoire.',
                'lignes.*.piece_jointe_path.required' => 'Un justificatif est obligatoire pour chaque ligne.',
                'lignes.*.piece_jointe_path.min' => 'Un justificatif est obligatoire pour chaque ligne.',
            ]
        );

        // La sous-catégorie est obligatoire uniquement pour les lignes de type standard.
        // Pour les lignes kilométriques, elle peut être null (le comptable tranchera).
        $validator->after(function ($v) use ($lignes): void {
            foreach ($lignes as $index => $ligne) {
                if ($ligne->type === NoteDeFraisLigneType::Standard && empty($ligne->sous_categorie_id)) {
                    $v->errors()->add("lignes.{$index}.sous_categorie_id", 'La sous-catégorie est obligatoire.');
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $ndf->update([
            'statut' => StatutNoteDeFrais::Soumise->value,
            'submitted_at' => now(),
        ]);

        $montantTotal = $ndf->lignes()->sum('montant');

        Log::info('portail.ndf.submitted', [
            'ndf_id' => $ndf->id,
            'tiers_id' => $ndf->tiers_id,
            'montant_total' => (float) $montantTotal,
        ]);
    }

    /**
     * Archive une NDF Payée ou Rejetée (action portail uniquement, irréversible en v0).
     *
     * @throws DomainException si la NDF est déjà archivée ou si son statut ne le permet pas
     */
    public function archive(NoteDeFrais $ndf): void
    {
        if ($ndf->isArchived()) {
            throw new DomainException('Cette note de frais est déjà archivée.');
        }

        $archivableStatuts = [StatutNoteDeFrais::Payee, StatutNoteDeFrais::Rejetee];
        if (! in_array($ndf->statut, $archivableStatuts, true)) {
            throw new DomainException('Seule une note de frais Payée ou Rejetée peut être archivée.');
        }

        $ndf->update(['archived_at' => now()]);

        Log::info('portail.ndf.archived', [
            'ndf_id' => $ndf->id,
            'tiers_id' => $ndf->tiers_id,
            'statut' => $ndf->getRawOriginal('statut'),
        ]);
    }

    /**
     * Supprime (softdelete) un brouillon et nettoie les fichiers PJ.
     *
     * @throws DomainException si la NDF n'est pas en brouillon
     */
    public function delete(NoteDeFrais $ndf): void
    {
        $deletableStatuts = [StatutNoteDeFrais::Brouillon, StatutNoteDeFrais::Soumise, StatutNoteDeFrais::Rejetee];
        if (! in_array($ndf->statut, $deletableStatuts, true)) {
            throw new DomainException('Seul un brouillon, une NDF soumise ou rejetée peut être supprimé(e).');
        }

        DB::transaction(function () use ($ndf): void {
            foreach ($ndf->lignes as $ligne) {
                if ($ligne->piece_jointe_path && Storage::disk('local')->exists($ligne->piece_jointe_path)) {
                    Storage::disk('local')->delete($ligne->piece_jointe_path);
                }
            }

            $ndf->delete(); // softdelete sur NDF, les lignes restent en DB
        });

        Log::info('portail.ndf.deleted', [
            'ndf_id' => $ndf->id,
            'tiers_id' => $ndf->tiers_id,
        ]);
    }
}
