<?php

declare(strict_types=1);

namespace App\Services\Rapports;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;

/**
 * Livre des immobilisations : état du registre au dernier jour d'un exercice.
 *
 * Les montants retenus sont ceux du PLAN d'amortissement, pas ceux du grand
 * livre — décision produit du 13/08/2026. Le rapport sert à préparer le bilan,
 * donc à un moment où les dotations de l'exercice ne sont pas nécessairement
 * générées ; les attendre le rendrait inutilisable précisément quand on en a
 * besoin. La conséquence est assumée : tant que les dotations ne sont pas
 * passées, ce rapport et le grand livre divergent de la dotation de l'année.
 *
 * L'écran du registre (ImmobilisationIndex), lui, affiche le cumul RÉELLEMENT
 * comptabilisé : les deux répondent à deux questions différentes.
 */
final class LivreImmobilisationsBuilder
{
    public function __construct(
        private readonly PlanAmortissementCalculator $calculator,
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * @return array{
     *     lignes: list<array<string, mixed>>,
     *     totaux: array{brut: int, cumul: int, dotation: int, vnc: int}
     * }
     */
    public function pourExercice(int $exercice): array
    {
        $finExercice = $this->exerciceService->dateRange($exercice)['end'];

        $lignes = Immobilisation::query()
            ->with(['compte', 'transaction'])
            ->orderBy('numero')
            ->get()
            // Une immobilisation acquise après la clôture ne figure pas au
            // registre de cet exercice : la fiche existe déjà en base dès sa
            // saisie, mais le bien n'était pas encore à l'actif.
            ->filter(fn (Immobilisation $immo): bool => $immo->transaction !== null
                && $immo->transaction->date->lessThanOrEqualTo($finExercice))
            ->map(function (Immobilisation $immo) use ($exercice): array {
                $brut = $immo->montantAcquisitionCentimes();
                $cumul = $this->calculator->cumulTheoriqueCentimes($immo, $exercice);

                // La dotation de l'année est l'écart entre deux cumuls, jamais
                // une part calculée à part : c'est ce qui garantit qu'aucun
                // centime ne se perd ni ne se crée sur la durée du plan.
                $cumulPrecedent = $this->calculator->cumulTheoriqueCentimes($immo, $exercice - 1);

                return [
                    'numero' => $immo->numero,
                    'libelle' => $immo->libelle,
                    'quantite' => (int) $immo->quantite,
                    'compte' => $immo->compte->numero_pcg.' — '.$immo->compte->intitule,
                    'date_acquisition' => $immo->transaction->date,
                    'date_mise_en_service' => $immo->date_mise_en_service,
                    'duree_label' => $immo->duree_label,
                    'montant_acquisition_centimes' => $brut,
                    'cumul_centimes' => $cumul,
                    'dotation_centimes' => $cumul - $cumulPrecedent,
                    'vnc_centimes' => $brut - $cumul,
                ];
            })
            ->values()
            ->all();

        return [
            'lignes' => $lignes,
            'totaux' => [
                'brut' => (int) collect($lignes)->sum('montant_acquisition_centimes'),
                'cumul' => (int) collect($lignes)->sum('cumul_centimes'),
                'dotation' => (int) collect($lignes)->sum('dotation_centimes'),
                'vnc' => (int) collect($lignes)->sum('vnc_centimes'),
            ],
        ];
    }
}
