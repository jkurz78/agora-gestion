<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TypeCategorie;
use App\Models\DepenseLigne;
use App\Models\RecetteLigne;
use App\Models\SousCategorie;
use Illuminate\Support\Facades\DB;

final class RapportService
{
    /**
     * Compte de résultat: charges and produits aggregated by code_cerfa.
     *
     * @return array{charges: list<array{code_cerfa: string|null, label: string, montant: float}>, produits: list<array{code_cerfa: string|null, label: string, montant: float}>}
     */
    public function compteDeResultat(int $exercice, ?array $operationIds = null): array
    {
        $startDate = "{$exercice}-09-01";
        $endDate = ($exercice + 1) . '-08-31';

        // Charges (from depense_lignes)
        $chargesQuery = DepenseLigne::query()
            ->join('sous_categories', 'depense_lignes.sous_categorie_id', '=', 'sous_categories.id')
            ->join('depenses', 'depense_lignes.depense_id', '=', 'depenses.id')
            ->whereNull('depense_lignes.deleted_at')
            ->whereNull('depenses.deleted_at')
            ->whereBetween('depenses.date', [$startDate, $endDate]);

        if ($operationIds) {
            $chargesQuery->whereIn('depense_lignes.operation_id', $operationIds);
        }

        $charges = $chargesQuery
            ->select(
                'sous_categories.code_cerfa',
                'sous_categories.nom as label',
                DB::raw('SUM(depense_lignes.montant) as montant')
            )
            ->groupBy('sous_categories.id', 'sous_categories.code_cerfa', 'sous_categories.nom')
            ->orderBy('sous_categories.code_cerfa')
            ->orderBy('sous_categories.nom')
            ->get()
            ->map(fn ($row) => [
                'code_cerfa' => $row->code_cerfa,
                'label' => $row->label,
                'montant' => (float) $row->montant,
            ])
            ->toArray();

        // Produits (from recette_lignes)
        $produitsQuery = RecetteLigne::query()
            ->join('sous_categories', 'recette_lignes.sous_categorie_id', '=', 'sous_categories.id')
            ->join('recettes', 'recette_lignes.recette_id', '=', 'recettes.id')
            ->whereNull('recette_lignes.deleted_at')
            ->whereNull('recettes.deleted_at')
            ->whereBetween('recettes.date', [$startDate, $endDate]);

        if ($operationIds) {
            $produitsQuery->whereIn('recette_lignes.operation_id', $operationIds);
        }

        $produits = $produitsQuery
            ->select(
                'sous_categories.code_cerfa',
                'sous_categories.nom as label',
                DB::raw('SUM(recette_lignes.montant) as montant')
            )
            ->groupBy('sous_categories.id', 'sous_categories.code_cerfa', 'sous_categories.nom')
            ->orderBy('sous_categories.code_cerfa')
            ->orderBy('sous_categories.nom')
            ->get()
            ->map(fn ($row) => [
                'code_cerfa' => $row->code_cerfa,
                'label' => $row->label,
                'montant' => (float) $row->montant,
            ])
            ->toArray();

        return ['charges' => $charges, 'produits' => $produits];
    }

    /**
     * Rapport par séances for a given operation.
     *
     * @return list<array{sous_categorie: string, type: string, seances: array<int, float>, total: float}>
     */
    public function rapportSeances(int $operationId): array
    {
        $rows = [];

        // Depense lignes with séances
        $depenseLignes = DepenseLigne::query()
            ->join('sous_categories', 'depense_lignes.sous_categorie_id', '=', 'sous_categories.id')
            ->whereNull('depense_lignes.deleted_at')
            ->where('depense_lignes.operation_id', $operationId)
            ->whereNotNull('depense_lignes.seance')
            ->select(
                'sous_categories.nom as sous_categorie',
                'depense_lignes.seance',
                DB::raw('SUM(depense_lignes.montant) as montant')
            )
            ->groupBy('sous_categories.id', 'sous_categories.nom', 'depense_lignes.seance')
            ->get();

        $grouped = [];
        foreach ($depenseLignes as $line) {
            $key = 'depense|' . $line->sous_categorie;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'sous_categorie' => $line->sous_categorie,
                    'type' => 'depense',
                    'seances' => [],
                    'total' => 0.0,
                ];
            }
            $grouped[$key]['seances'][(int) $line->seance] = (float) $line->montant;
            $grouped[$key]['total'] += (float) $line->montant;
        }

        // Recette lignes with séances
        $recetteLignes = RecetteLigne::query()
            ->join('sous_categories', 'recette_lignes.sous_categorie_id', '=', 'sous_categories.id')
            ->whereNull('recette_lignes.deleted_at')
            ->where('recette_lignes.operation_id', $operationId)
            ->whereNotNull('recette_lignes.seance')
            ->select(
                'sous_categories.nom as sous_categorie',
                'recette_lignes.seance',
                DB::raw('SUM(recette_lignes.montant) as montant')
            )
            ->groupBy('sous_categories.id', 'sous_categories.nom', 'recette_lignes.seance')
            ->get();

        foreach ($recetteLignes as $line) {
            $key = 'recette|' . $line->sous_categorie;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'sous_categorie' => $line->sous_categorie,
                    'type' => 'recette',
                    'seances' => [],
                    'total' => 0.0,
                ];
            }
            $grouped[$key]['seances'][(int) $line->seance] = (float) $line->montant;
            $grouped[$key]['total'] += (float) $line->montant;
        }

        return array_values($grouped);
    }

    /**
     * Generate CSV string with semicolon separator (French convention).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $headers
     */
    public function toCsv(array $rows, array $headers): string
    {
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($output, $row, ';');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }
}
