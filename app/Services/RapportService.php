<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Rapports\CompteResultatBuilder;
use App\Services\Rapports\FluxTresorerieBuilder;
use App\Services\Rapports\OperationsEligiblesQuery;

final class RapportService
{
    private readonly CompteResultatBuilder $compteResultat;

    private readonly FluxTresorerieBuilder $fluxTresorerie;

    private readonly OperationsEligiblesQuery $eligibles;

    public function __construct(
        ?CompteResultatBuilder $compteResultat = null,
        ?FluxTresorerieBuilder $fluxTresorerie = null,
        ?OperationsEligiblesQuery $operationsEligibles = null,
    ) {
        $this->compteResultat = $compteResultat ?? app(CompteResultatBuilder::class);
        $this->fluxTresorerie = $fluxTresorerie ?? app(FluxTresorerieBuilder::class);
        $this->eligibles = $operationsEligibles ?? app(OperationsEligiblesQuery::class);
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
     * SEL-01 — Opérations ayant un mouvement de résultat sur l'exercice.
     * Source unique du sélecteur de l'écran et de la validation des exports.
     *
     * @return list<int>
     */
    public function operationsEligibles(int $exercice): array
    {
        return $this->eligibles->pourExercice($exercice);
    }

    /**
     * SEL-04 — Intersecte une sélection non fiable avec les opérations
     * éligibles. Un identifiant absent de la liste est écarté en silence :
     * ni rapport partiel, ni fuite sur une autre opération.
     *
     * @param  array<mixed>  $selection
     * @return list<int>
     */
    public function normaliserOperations(array $selection, int $exercice): array
    {
        return $this->eligibles->normaliser($selection, $exercice);
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
