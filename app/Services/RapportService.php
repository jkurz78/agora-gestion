<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;
use Illuminate\Support\Collection;

final class RapportService
{
    private readonly CompteResultatBuilder $compteResultat;

    private readonly FluxTresorerieBuilder $fluxTresorerie;

    public function __construct(
        ?CompteResultatBuilder $compteResultat = null,
        ?FluxTresorerieBuilder $fluxTresorerie = null,
    ) {
        $this->compteResultat = $compteResultat ?? app(CompteResultatBuilder::class);
        $this->fluxTresorerie = $fluxTresorerie ?? app(FluxTresorerieBuilder::class);
    }

    /**
     * Compte de résultat complet : hiérarchie famille/compte avec N-1 et budget.
     * Pas de filtre opération.
     *
     * @return array{charges: list<array>, produits: list<array>}
     */
    public function compteDeResultat(int $exercice): array
    {
        return $this->compteResultat->compteDeResultat($exercice);
    }

    /**
     * Bloc « provisions de fin d'exercice » du compte de résultat (détail + totaux + résultat brut/net).
     *
     * En mode partie double, les provisions vivent dans le grand livre sous les comptes
     * 681/781 et sont déjà agrégées dans les charges/produits du compte de résultat. Les
     * ré-additionner depuis la table `provisions` legacy provoquerait un double-comptage —
     * on renvoie donc des totaux nuls et un résultat net = résultat courant.
     *
     * En mode legacy, les provisions n'ont pas d'écriture comptable : on les lit depuis la
     * table `provisions` et on les applique en cascade (résultat courant → brut → net).
     *
     * @return array{
     *     provisions: Collection,
     *     provisionsN1: Collection,
     *     extournes: Collection,
     *     extournesN1: Collection,
     *     totalProvisions: float,
     *     totalProvisionsN1: float,
     *     totalExtournes: float,
     *     totalExtournesN1: float,
     *     resultatBrut: float,
     *     resultatBrutN1: float,
     *     resultatNet: float,
     *     resultatNetN1: float,
     * }
     */
    public function compteDeResultatProvisions(int $exercice, float $resultatCourant, float $resultatCourantN1): array
    {
        if (config('compta.use_partie_double', false)) {
            return [
                'provisions' => collect(),
                'provisionsN1' => collect(),
                'extournes' => collect(),
                'extournesN1' => collect(),
                'totalProvisions' => 0.0,
                'totalProvisionsN1' => 0.0,
                'totalExtournes' => 0.0,
                'totalExtournesN1' => 0.0,
                'resultatBrut' => $resultatCourant,
                'resultatBrutN1' => $resultatCourantN1,
                'resultatNet' => $resultatCourant,
                'resultatNetN1' => $resultatCourantN1,
            ];
        }

        $provisionService = app(ProvisionService::class);
        $totalProvisions = $provisionService->totalProvisions($exercice);
        $totalProvisionsN1 = $provisionService->totalProvisions($exercice - 1);
        $totalExtournes = $provisionService->totalExtournes($exercice);
        $totalExtournesN1 = $provisionService->totalExtournes($exercice - 1);

        $resultatBrut = $resultatCourant + $totalExtournes;
        $resultatBrutN1 = $resultatCourantN1 + $totalExtournesN1;

        return [
            'provisions' => $provisionService->provisionsExercice($exercice),
            'provisionsN1' => $provisionService->provisionsExercice($exercice - 1),
            'extournes' => $provisionService->extournesExercice($exercice),
            'extournesN1' => $provisionService->extournesExercice($exercice - 1),
            'totalProvisions' => $totalProvisions,
            'totalProvisionsN1' => $totalProvisionsN1,
            'totalExtournes' => $totalExtournes,
            'totalExtournesN1' => $totalExtournesN1,
            'resultatBrut' => $resultatBrut,
            'resultatBrutN1' => $resultatBrutN1,
            'resultatNet' => $resultatBrut + $totalProvisions,
            'resultatNetN1' => $resultatBrutN1 + $totalProvisionsN1,
        ];
    }

    /**
     * Compte de résultat filtré par opérations. Pas de N-1 ni budget. Cotisations exclues.
     * Optionnellement ventilé par séances et/ou par tiers.
     *
     * @param  array<int>  $operationIds
     * @return array{charges: list<array>, produits: list<array>, seances?: list<int>}
     */
    public function compteDeResultatOperations(
        int $exercice,
        array $operationIds,
        bool $parSeances = false,
        bool $parTiers = false,
        bool $previsionnel = false,
        bool $parOperations = false,
    ): array {
        return $this->compteResultat->compteDeResultatOperations($exercice, $operationIds, $parSeances, $parTiers, $previsionnel, $parOperations);
    }

    /**
     * Rapport par séances : hiérarchie famille/compte avec une colonne par séance.
     *
     * @param  array<int>  $operationIds
     * @return array{seances: list<int>, charges: list<array>, produits: list<array>}
     */
    public function rapportSeances(int $exercice, array $operationIds): array
    {
        return $this->compteResultat->rapportSeances($exercice, $operationIds);
    }

    /**
     * État de flux de trésorerie consolidé.
     *
     * @return array{exercice: array, synthese: array, rapprochement: array, mensuel: list<array>, ecritures_non_pointees: list<array>}
     */
    public function fluxTresorerie(int $exercice): array
    {
        return $this->fluxTresorerie->fluxTresorerie($exercice);
    }

    /**
     * Génère un CSV avec séparateur point-virgule (convention française).
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
