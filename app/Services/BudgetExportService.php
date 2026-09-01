<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetLine;
use App\Services\Compta\PlanComptableSelecteur;

final class BudgetExportService
{
    /**
     * Retourne les lignes d'export triées dépenses puis recettes.
     *
     * Groupement famille/compte identique à l'écran Budget ({@see PlanComptableSelecteur}).
     *
     * @param  int  $exerciceCible  Valeur à écrire dans la colonne exercice
     * @param  string  $source  'zero' | 'realise' | 'budget'
     * @param  int  $sourceExercice  Exercice source des montants
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function rows(int $exerciceCible, string $source, int $sourceExercice): array
    {
        $budgetService = app(BudgetService::class);
        $exerciceLabel = app(ExerciceService::class)->label($exerciceCible);

        // Pré-charger le budget de l'exercice source en une seule requête
        $budgetMap = $source === 'budget'
            ? BudgetLine::forExercice($sourceExercice)
                ->enveloppes()
                ->pluck('montant_prevu', 'compte_id')
                ->map(fn ($v) => (float) $v)
                ->all()
            : [];

        $realiseMap = $budgetService->realiseParCompte($sourceExercice);

        $rows = [];

        foreach (['depense', 'recette'] as $type) {
            $groupes = PlanComptableSelecteur::groupesPourType($type);

            foreach ($groupes as $codeFamille => $groupe) {
                $groupeLabel = $groupe['famille']?->libelle() ?? $codeFamille;

                foreach ($groupe['comptes'] as $compte) {
                    $realiseReference = $realiseMap[$compte->id] ?? 0.0;

                    $montant = match ($source) {
                        'realise' => $realiseReference,
                        'budget' => $budgetMap[$compte->id] ?? 0.0,
                        default => 0.0,
                    };

                    $rows[] = [
                        $exerciceLabel,
                        $groupeLabel,
                        $compte->intitule,
                        $montant > 0 ? number_format($montant, 2, '.', '') : '',
                        $realiseReference != 0.0 ? number_format($realiseReference, 2, '.', '') : '',
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * En-têtes de colonnes, la 5ᵉ portant le libellé de l'exercice de référence.
     *
     * @return list<string>
     */
    public function enTetes(int $sourceExercice): array
    {
        return ['exercice', 'famille', 'compte', 'montant_prevu', 'realise_'.app(ExerciceService::class)->label($sourceExercice)];
    }

    /**
     * Convertit les lignes en chaîne CSV UTF-8 avec séparateur ';'.
     *
     * L'en-tête reprend {@see enTetes()} — la même source que le XLSX — afin
     * que les deux formats d'export ne divergent jamais sur le libellé de la
     * 5ᵉ colonne (le CSV codait auparavant "realise_reference" en dur, quand
     * le XLSX produisait "realise_2025-2026").
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows
     * @param  int  $sourceExercice  Exercice de référence, transmis à enTetes()
     */
    public function toCsv(array $rows, int $sourceExercice): string
    {
        $lines = [implode(';', $this->enTetes($sourceExercice))];

        foreach ($rows as $row) {
            $escaped = array_map(
                fn (string $v): string => str_contains($v, ';') || str_contains($v, '"')
                    ? '"'.str_replace('"', '""', $v).'"'
                    : $v,
                $row
            );
            $lines[] = implode(';', $escaped);
        }

        return implode("\n", $lines)."\n";
    }
}
