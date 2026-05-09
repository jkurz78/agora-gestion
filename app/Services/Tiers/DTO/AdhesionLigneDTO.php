<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use App\Models\Adhesion;

final class AdhesionLigneDTO
{
    public function __construct(
        public readonly Adhesion $adhesion,
    ) {}

    public function libelleExercice(): string
    {
        if ($this->adhesion->exercice !== null) {
            return 'Ex. '.$this->adhesion->exercice.'-'.($this->adhesion->exercice + 1);
        }

        return '—'; // mode durée → la colonne Formule/Validité affichera l'intervalle
    }

    public function libelleType(): string
    {
        return $this->adhesion->estGratuite() ? 'Offerte' : 'Cotisation';
    }
}
