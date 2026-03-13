<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Recette;
use Illuminate\Support\Facades\DB;

final class RecetteService
{
    public function create(array $data, array $lignes): Recette
    {
        return DB::transaction(function () use ($data, $lignes) {
            $data['saisi_par'] = auth()->id();
            $recette = Recette::create($data);
            foreach ($lignes as $ligne) {
                $recette->lignes()->create($ligne);
            }

            return $recette;
        });
    }

    public function update(Recette $recette, array $data, array $lignes): Recette
    {
        return DB::transaction(function () use ($recette, $data, $lignes) {
            $recette->update($data);
            $recette->lignes()->forceDelete();
            foreach ($lignes as $ligne) {
                $recette->lignes()->create($ligne);
            }

            return $recette->fresh();
        });
    }

    public function delete(Recette $recette): void
    {
        if ($recette->rapprochement_id !== null) {
            throw new \RuntimeException("Cette recette est pointée dans un rapprochement et ne peut pas être supprimée.");
        }

        DB::transaction(function () use ($recette) {
            $recette->lignes()->delete();
            $recette->delete();
        });
    }
}
