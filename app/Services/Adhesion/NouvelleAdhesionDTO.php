<?php

declare(strict_types=1);

namespace App\Services\Adhesion;

use App\Enums\ModePaiement;
use Illuminate\Support\Carbon;

final class NouvelleAdhesionDTO
{
    public function __construct(
        public readonly int $tiersId,
        public readonly int $formuleId,
        public readonly ?int $exercice,
        public readonly ?Carbon $dateDebut,
        public readonly float $montant,
        public readonly ?string $notes,
        public readonly ?string $datePaiement,
        public readonly ?ModePaiement $modePaiement,
        public readonly ?int $compteId,
        public readonly ?string $reference,
    ) {}

    public function isGratuite(): bool
    {
        return $this->montant <= 0.0;
    }
}
