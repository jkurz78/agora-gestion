<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Compte;
use App\Models\TransactionLigne;

final class BudgetService
{
    /**
     * Compute le réalisé (montant réel) pour un compte du plan comptable sur un exercice.
     *
     * Compte de classe 6 (charges) : lignes des transactions de type depense.
     * Compte de classe 7 (produits) : lignes des transactions de type recette.
     */
    public function realise(int $compteId, int $exercice): float
    {
        $compte = Compte::findOrFail($compteId);

        // Les bornes viennent du paramétrage du tenant, jamais d'un calcul
        // local : une association en exercice civil ou décalé n'a pas les
        // mêmes dates, et les figer ici rendait son réalisé faux.
        $range = app(ExerciceService::class)->dateRange($exercice);
        $startDate = $range['start']->toDateString();
        $endDate = $range['end']->toDateString();

        $typeValue = (int) $compte->classe === 6 ? 'depense' : 'recette';

        return (float) TransactionLigne::where('compte_id', $compteId)
            ->whereHas('transaction', fn ($q) => $q->where('type', $typeValue)->whereBetween('date', [$startDate, $endDate]))
            ->sum('montant');
    }
}
