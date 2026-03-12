<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompteBancaire;
use App\Models\Cotisation;
use App\Models\Depense;
use App\Models\Don;
use App\Models\Recette;

final class RapprochementService
{
    public function soldeTheorique(CompteBancaire $compte, ?string $dateFin = null): float
    {
        $solde = (float) $compte->solde_initial;

        $solde += (float) Recette::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant_total');

        $solde += (float) Don::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant');

        $solde += (float) Cotisation::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date_paiement', '<=', $dateFin))
            ->sum('montant');

        $solde -= (float) Depense::where('compte_id', $compte->id)->where('pointe', true)
            ->when($dateFin, fn ($q) => $q->where('date', '<=', $dateFin))
            ->sum('montant_total');

        return $solde;
    }

    public function togglePointe(string $type, int $id): bool
    {
        $model = match ($type) {
            'depense' => Depense::findOrFail($id),
            'recette' => Recette::findOrFail($id),
            'don' => Don::findOrFail($id),
            'cotisation' => Cotisation::findOrFail($id),
        };
        $model->pointe = ! $model->pointe;
        $model->save();

        return $model->pointe;
    }
}
