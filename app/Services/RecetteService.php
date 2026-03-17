<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Recette;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class RecetteService
{
    public function create(array $data, array $lignes): Recette
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $data['numero_piece'] = app(NumeroPieceService::class)->assign(Carbon::parse($data['date']));
            $recette = Recette::create($data);
            foreach ($lignes as $ligne) {
                $recette->lignes()->create($ligne);
            }

            return $recette;
        });
    }

    public function update(Recette $recette, array $data, array $lignes): Recette
    {
        $recette->loadMissing('rapprochement');

        if ($recette->isLockedByRapprochement()) {
            $this->assertLockedInvariants($recette, $data, $lignes);
        }

        return DB::transaction(function () use ($recette, $data, $lignes) {
            $recette->update($data);

            if ($recette->isLockedByRapprochement()) {
                // Pièce verrouillée : mise à jour ligne par ligne via ID
                foreach ($lignes as $ligneData) {
                    $recette->lignes()->where('id', $ligneData['id'])->update([
                        'operation_id' => $ligneData['operation_id'],
                        'seance'       => $ligneData['seance'],
                        'notes'        => $ligneData['notes'],
                    ]);
                }
            } else {
                // Pièce non verrouillée : comportement existant
                $recette->lignes()->forceDelete();
                foreach ($lignes as $ligne) {
                    $recette->lignes()->create($ligne);
                }
            }

            return $recette->fresh();
        });
    }

    private function assertLockedInvariants(Recette $recette, array $data, array $lignes): void
    {
        if ($recette->date->format('Y-m-d') !== $data['date']) {
            throw new \RuntimeException('La date ne peut pas être modifiée sur une recette rapprochée.');
        }

        if ((int) $recette->compte_id !== (int) $data['compte_id']) {
            throw new \RuntimeException('Le compte bancaire ne peut pas être modifié sur une recette rapprochée.');
        }

        if ((int) round((float) $recette->montant_total * 100) !== (int) round((float) $data['montant_total'] * 100)) {
            throw new \RuntimeException('Le montant total ne peut pas être modifié sur une recette rapprochée.');
        }

        $existingLignes = $recette->lignes()->get()->keyBy('id');

        if (count($lignes) !== $existingLignes->count()) {
            throw new \RuntimeException('Le nombre de lignes ne peut pas être modifié sur une recette rapprochée.');
        }

        foreach ($lignes as $ligneData) {
            $id = $ligneData['id'] ?? null;
            if ($id === null || ! $existingLignes->has($id)) {
                throw new \RuntimeException('Ligne inconnue ou sans identifiant sur une recette rapprochée.');
            }
            $existing = $existingLignes->get($id);
            if ((int) round((float) $existing->montant * 100) !== (int) round((float) $ligneData['montant'] * 100)) {
                throw new \RuntimeException('Le montant d\'une ligne ne peut pas être modifié sur une recette rapprochée.');
            }
            if ((int) $existing->sous_categorie_id !== (int) $ligneData['sous_categorie_id']) {
                throw new \RuntimeException('La sous-catégorie d\'une ligne ne peut pas être modifiée sur une recette rapprochée.');
            }
        }
    }

    public function delete(Recette $recette): void
    {
        if ($recette->rapprochement_id !== null) {
            throw new \RuntimeException('Cette recette est pointée dans un rapprochement et ne peut pas être supprimée.');
        }

        DB::transaction(function () use ($recette) {
            $recette->lignes()->delete();
            $recette->delete();
        });
    }
}
