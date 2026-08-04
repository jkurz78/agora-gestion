<?php

declare(strict_types=1);

namespace App\Services\Immobilisation;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use Carbon\CarbonImmutable;

/**
 * Amortissement linéaire au prorata mensuel.
 *
 * Règles (spec § 6) :
 *  - le mois de mise en service compte pour un mois entier ;
 *  - cumul théorique = montant × mois écoulés / durée, arrondi au centime ;
 *  - dotation = cumul théorique − cumul déjà comptabilisé.
 *
 * Cette dernière règle absorbe les arrondis au lieu de les accumuler : la
 * dernière dotation solde le bien à l'euro près par construction, et une durée
 * ou un montant corrigés en cours de vie se rattrapent d'eux-mêmes sur
 * l'exercice suivant.
 *
 * Tous les calculs se font en centimes entiers — aucun flottant intermédiaire.
 */
final class PlanAmortissementCalculator
{
    public function __construct(private readonly ExerciceService $exerciceService) {}

    /**
     * Nombre de mois écoulés entre le mois de mise en service (inclus) et le
     * mois de clôture de l'exercice (inclus), plafonné à la durée, plancher 0.
     */
    public function moisEcoules(Immobilisation $immobilisation, int $exercice): int
    {
        $finExercice = CarbonImmutable::instance(
            $this->exerciceService->dateRange($exercice)['end']->toDateTime()
        );

        $mes = CarbonImmutable::instance($immobilisation->date_mise_en_service->toDateTime());

        $mois = (($finExercice->year - $mes->year) * 12) + ($finExercice->month - $mes->month) + 1;

        return max(0, min($mois, (int) $immobilisation->duree_mois));
    }

    /** Cumul théorique en centimes à la fin de l'exercice donné. */
    public function cumulTheoriqueCentimes(Immobilisation $immobilisation, int $exercice): int
    {
        $dureeMois = (int) $immobilisation->duree_mois;

        if ($dureeMois <= 0) {
            return 0;
        }

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $moisEcoules = $this->moisEcoules($immobilisation, $exercice);

        return (int) round($montantCentimes * $moisEcoules / $dureeMois);
    }

    /**
     * Dotation de l'exercice, en centimes.
     *
     * Jamais négative : si le cumul comptabilisé dépasse le cumul théorique
     * (durée allongée après coup), la dotation est nulle et l'écart se résorbe
     * sur les exercices suivants.
     */
    public function dotationCentimes(
        Immobilisation $immobilisation,
        int $exercice,
        int $cumulComptabiliseCentimes,
    ): int {
        $cumulTheorique = $this->cumulTheoriqueCentimes($immobilisation, $exercice);

        return max(0, $cumulTheorique - $cumulComptabiliseCentimes);
    }
}
