<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;

final class SoldeService
{
    public function solde(CompteBancaire $compte): float
    {
        $dateRef = $compte->date_solde_initial?->toDateString() ?? '1900-01-01';

        $entrees =
            (float) $compte->recettes()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) $compte->cotisations()->where('date_paiement', '>=', $dateRef)->sum('montant')
            + (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        $sorties =
            (float) $compte->depenses()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) $compte->dons()->where('date', '>=', $dateRef)->sum('montant')
            + (float) VirementInterne::where('compte_source_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        return round((float) $compte->solde_initial + $entrees - $sorties, 2);
    }
}
