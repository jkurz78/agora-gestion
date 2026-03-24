<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\VirementInterne;

final class SoldeService
{
    /**
     * Compute the current real balance of a bank account.
     *
     * Starts from solde_initial (at date_solde_initial) and adds all inflows
     * (recettes, virements received) and subtracts all outflows
     * (depenses, virements sent) since that date. Soft-deleted records
     * are automatically excluded via Eloquent global scopes.
     */
    public function solde(CompteBancaire $compte): float
    {
        $dateRef = $compte->date_solde_initial?->toDateString() ?? '1900-01-01';

        $entrees =
            (float) $compte->recettes()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) VirementInterne::where('compte_destination_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        $sorties =
            (float) $compte->depenses()->where('date', '>=', $dateRef)->sum('montant_total')
            + (float) VirementInterne::where('compte_source_id', $compte->id)
                ->where('date', '>=', $dateRef)
                ->sum('montant');

        return round((float) $compte->solde_initial + $entrees - $sorties, 2);
    }
}
