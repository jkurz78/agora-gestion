<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Models\Categorie;

final class BudgetExportService
{
    /**
     * Retourne les lignes d'export triées dépenses puis recettes.
     *
     * @param  int       $exerciceCible  Valeur à écrire dans la colonne exercice
     * @param  int|null  $sourceExercice Exercice source pour les montants réalisés ; null = zéro partout
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function rows(int $exerciceCible, ?int $sourceExercice): array
    {
        $rows = [];

        foreach ([TypeCategorie::Depense, TypeCategorie::Recette] as $type) {
            $categories = Categorie::where('type', $type)
                ->with(['sousCategories' => fn ($q) => $q->orderBy('nom')])
                ->orderBy('nom')
                ->get();

            foreach ($categories as $categorie) {
                foreach ($categorie->sousCategories as $sc) {
                    $montant = $sourceExercice !== null
                        ? app(\App\Services\BudgetService::class)->realise($sc->id, $sourceExercice)
                        : 0.0;

                    $rows[] = [
                        app(\App\Services\ExerciceService::class)->label($exerciceCible),
                        $sc->nom,
                        $montant > 0 ? number_format($montant, 2, '.', '') : '',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Convertit les lignes en chaîne CSV UTF-8 avec séparateur ';'.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    public function toCsv(array $rows): string
    {
        $lines = ['exercice;sous_categorie;montant_prevu'];

        foreach ($rows as $row) {
            $escaped = array_map(
                fn (string $v): string => str_contains($v, ';') || str_contains($v, '"')
                    ? '"' . str_replace('"', '""', $v) . '"'
                    : $v,
                $row
            );
            $lines[] = implode(';', $escaped);
        }

        return implode("\n", $lines) . "\n";
    }
}
