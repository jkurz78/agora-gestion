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

    /**
     * Plan complet, de l'exercice de mise en service jusqu'au solde du bien.
     *
     * Les exercices déjà comptabilisés portent le montant réellement écrit ;
     * les suivants sont des projections calculées à la volée — rien n'est
     * stocké, donc rien ne peut devenir périmé.
     *
     * Point de construction unique : la fiche (ImmobilisationShow) et son PDF
     * (ImmobilisationPdfController) l'appellent tous les deux — le PDF est un
     * rendu figé de la fiche, il ne doit jamais pouvoir en diverger. Suppose
     * la relation `dotations` déjà chargée sur $immobilisation (les deux
     * appelants le font).
     *
     * @return list<LignePlanAmortissement>
     */
    public function plan(Immobilisation $immobilisation): array
    {
        $exercice = $this->exerciceService->anneeForDate(
            CarbonImmutable::parse($immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;

        // Borne dure : la durée en mois ne peut pas s'étaler sur plus d'exercices
        // que (durée / 12) + 2, ce qui protège d'une boucle infinie si le calcul
        // venait à stagner.
        $borne = intdiv((int) $immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $dotationEnregistree = $immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $dotationEnregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $dotationEnregistree->montant) * 100)
                : $this->dotationCentimes($immobilisation, $exercice, $cumul);

            $cumul += $dotation;

            $plan[] = new LignePlanAmortissement(
                exercice: $exercice,
                moisEcoules: $this->moisEcoules($immobilisation, $exercice),
                dotationCentimes: $dotation,
                cumulCentimes: $cumul,
                valeurNetteCentimes: $montantCentimes - $cumul,
                comptabilisee: $comptabilisee,
                transactionId: $comptabilisee ? (int) $dotationEnregistree->transaction_id : null,
            );

            $exercice++;
        }

        return $plan;
    }
}
