<?php

declare(strict_types=1);

namespace App\Services\Portail\NoteDeFrais;

use App\Enums\StatutNoteDeFrais;
use App\Models\NoteDeFrais;
use App\Models\NoteDeFraisLigne;
use App\Models\Tiers;
use App\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
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
     *         libelle: string|null,
     *         montant: float|int,
     *         sous_categorie_id: int|null,
     *         operation_id?: int|null,
     *         seance_id?: int|null,
     *         piece_jointe_path: string|null,
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

                if ($ndf->statut !== StatutNoteDeFrais::Brouillon) {
                    throw new DomainException('Seul un brouillon peut être modifié.');
                }

                $ndf->update([
                    'date' => $data['date'],
                    'libelle' => $data['libelle'] ?? '',
                ]);

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

            foreach ($data['lignes'] as $ligneData) {
                NoteDeFraisLigne::create([
                    'note_de_frais_id' => $ndf->id,
                    'libelle' => $ligneData['libelle'] ?? null,
                    'montant' => $ligneData['montant'],
                    'sous_categorie_id' => $ligneData['sous_categorie_id'] ?? null,
                    'operation_id' => $ligneData['operation_id'] ?? null,
                    'seance_id' => $ligneData['seance_id'] ?? null,
                    'piece_jointe_path' => $ligneData['piece_jointe_path'] ?? null,
                ]);
            }

            return $ndf->refresh();
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
                'lignes.*.sous_categorie_id' => ['required'],
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
                'lignes.*.sous_categorie_id.required' => 'La sous-catégorie est obligatoire.',
                'lignes.*.montant.gt' => 'Le montant doit être supérieur à zéro.',
                'lignes.*.montant.required' => 'Le montant est obligatoire.',
                'lignes.*.piece_jointe_path.required' => 'Un justificatif est obligatoire pour chaque ligne.',
                'lignes.*.piece_jointe_path.min' => 'Un justificatif est obligatoire pour chaque ligne.',
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $ndf->update([
            'statut' => StatutNoteDeFrais::Soumise->value,
            'submitted_at' => now(),
        ]);
    }

    /**
     * Supprime (softdelete) un brouillon et nettoie les fichiers PJ.
     *
     * @throws DomainException si la NDF n'est pas en brouillon
     */
    public function delete(NoteDeFrais $ndf): void
    {
        if ($ndf->statut !== StatutNoteDeFrais::Brouillon) {
            throw new DomainException('Seul un brouillon peut être supprimé.');
        }

        DB::transaction(function () use ($ndf): void {
            foreach ($ndf->lignes as $ligne) {
                if ($ligne->piece_jointe_path && Storage::disk('local')->exists($ligne->piece_jointe_path)) {
                    Storage::disk('local')->delete($ligne->piece_jointe_path);
                }
            }

            $ndf->delete(); // softdelete sur NDF, les lignes restent en DB
        });
    }
}
