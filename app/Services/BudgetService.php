<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Models\DepenseLigne;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;

final class BudgetService
{
    /**
     * Compute the réalisé (actual) amount for a sous-catégorie in a given exercice.
     *
     * For depense sous-categories: sum of depense_lignes.montant where depense.date is in exercice range.
     * For recette sous-categories: sum of recette_lignes.montant where recette.date is in exercice range.
     */
    public function realise(int $sousCategorieId, int $exercice): float
    {
        $sousCategorie = SousCategorie::with('categorie')->findOrFail($sousCategorieId);

        $startDate = "{$exercice}-09-01";
        $endDate = ($exercice + 1) . '-08-31';

        if ($sousCategorie->categorie->type === TypeCategorie::Depense) {
            return (float) DepenseLigne::where('sous_categorie_id', $sousCategorieId)
                ->whereHas('depense', function ($q) use ($startDate, $endDate) {
                    $q->whereBetween('date', [$startDate, $endDate]);
                })
                ->whereNull('deleted_at')
                ->sum('montant');
        }

        return (float) RecetteLigne::where('sous_categorie_id', $sousCategorieId)
            ->whereHas('recette', function ($q) use ($startDate, $endDate) {
                $q->whereBetween('date', [$startDate, $endDate]);
            })
            ->whereNull('deleted_at')
            ->sum('montant');
    }
}
