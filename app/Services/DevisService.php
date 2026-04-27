<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StatutDevis;
use App\Models\Devis;
use App\Support\CurrentAssociation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class DevisService
{
    public function __construct(
        private readonly ExerciceService $exerciceService,
    ) {}

    /**
     * Create a new brouillon devis for the given tiers.
     *
     * Resolves date_emission (defaults to today), reads devis_validite_jours
     * from the current association (defaults to 30), computes date_validite,
     * and determines the exercice from the emission date via ExerciceService.
     */
    public function creer(int $tiersId, ?Carbon $date = null): Devis
    {
        $dateEmission = $date ?? Carbon::today();

        return DB::transaction(function () use ($tiersId, $dateEmission): Devis {
            $association = CurrentAssociation::get();

            $validiteJours = $association->devis_validite_jours ?? 30;
            $dateValidite = $dateEmission->copy()->addDays($validiteJours);

            $exercice = $this->exerciceService->anneeForDate($dateEmission);

            return Devis::create([
                'tiers_id' => $tiersId,
                'date_emission' => $dateEmission->toDateString(),
                'date_validite' => $dateValidite->toDateString(),
                'statut' => StatutDevis::Brouillon,
                'montant_total' => 0,
                'exercice' => $exercice,
                'numero' => null,
                'saisi_par_user_id' => auth()->id(),
            ]);
        });
    }
}
