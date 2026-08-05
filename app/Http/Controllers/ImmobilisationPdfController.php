<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Immobilisation;
use App\Services\ExerciceService;
use App\Services\Immobilisation\PlanAmortissementCalculator;
use App\Support\CurrentAssociation;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

final class ImmobilisationPdfController extends Controller
{
    public function __invoke(Immobilisation $immobilisation): Response
    {
        $immobilisation->load(['compte', 'compteAmortissement', 'dotations', 'transaction.tiers']);

        $pdf = Pdf::loadView('pdf.immobilisation', [
            'immobilisation' => $immobilisation,
            'association' => CurrentAssociation::get(),
            'plan' => $this->plan($immobilisation),
            'exerciceService' => app(ExerciceService::class),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream('immobilisation-'.$immobilisation->numero.'.pdf');
    }

    /**
     * Même construction que ImmobilisationShow — le PDF est un rendu figé de la
     * fiche, il ne doit pas diverger de l'écran.
     *
     * @return list<array{exercice: int, moisEcoules: int, dotationCentimes: int, cumulCentimes: int, valeurNetteCentimes: int, comptabilisee: bool}>
     */
    private function plan(Immobilisation $immobilisation): array
    {
        $calculator = app(PlanAmortissementCalculator::class);
        $exerciceService = app(ExerciceService::class);

        $exercice = $exerciceService->anneeForDate(
            CarbonImmutable::parse($immobilisation->date_mise_en_service->toDateString())
        );

        $montantCentimes = $immobilisation->montantAcquisitionCentimes();
        $plan = [];
        $cumul = 0;
        $borne = intdiv((int) $immobilisation->duree_mois, 12) + 2;

        for ($i = 0; $i < $borne && $cumul < $montantCentimes; $i++) {
            $enregistree = $immobilisation->dotations->firstWhere('exercice', $exercice);
            $comptabilisee = $enregistree !== null;

            $dotation = $comptabilisee
                ? (int) round(((float) $enregistree->montant) * 100)
                : $calculator->dotationCentimes($immobilisation, $exercice, $cumul);

            $cumul += $dotation;

            $plan[] = [
                'exercice' => $exercice,
                'moisEcoules' => $calculator->moisEcoules($immobilisation, $exercice),
                'dotationCentimes' => $dotation,
                'cumulCentimes' => $cumul,
                'valeurNetteCentimes' => $montantCentimes - $cumul,
                'comptabilisee' => $comptabilisee,
            ];

            $exercice++;
        }

        return $plan;
    }
}
