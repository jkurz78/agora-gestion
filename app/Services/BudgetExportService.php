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
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public function rows(int $exerciceCible, string $source, int $sourceExercice): array
    {
        $budgetService = app(BudgetService::class);
        $exerciceLabel = app(ExerciceService::class)->label($exerciceCible);

        // Pré-charger le budget de l'exercice source en une seule requête
        $budgetMap = $source === 'budget'
            ? BudgetLine::forExercice($sourceExercice)
                ->pluck('montant_prevu', 'compte_id')
                ->map(fn ($v) => (float) $v)
                ->all()
            : [];

        $rows = [];

        foreach (['depense', 'recette'] as $type) {
            $groupes = PlanComptableSelecteur::groupesPourType($type);

            foreach ($groupes as $codeFamille => $groupe) {
                $groupeLabel = $groupe['famille']?->libelle() ?? $codeFamille;

                foreach ($groupe['comptes'] as $compte) {
                    $montant = match ($source) {
                        'realise' => $budgetService->realise((int) $compte->id, $sourceExercice),
                        'budget' => $budgetMap[$compte->id] ?? 0.0,
                        default => 0.0,
                    };

                    $rows[] = [
                        $exerciceLabel,
                        $groupeLabel,
                        $compte->intitule,
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
        $lines = ['exercice;categorie;sous_categorie;montant_prevu'];

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
