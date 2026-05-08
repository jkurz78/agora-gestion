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
        return $this->adhesion->exercice.' – '.($this->adhesion->exercice + 1);
    }

    public function libelleType(): string
    {
        return $this->adhesion->gratuite ? 'Offerte' : 'Cotisation';
    }
}
