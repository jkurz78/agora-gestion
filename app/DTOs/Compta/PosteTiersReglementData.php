<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use App\Enums\ModePaiement;
use Carbon\CarbonImmutable;

final readonly class PosteTiersReglementData
{
    public function __construct(
        public int $ligneId,
        public int $montantCentimes,
        public CarbonImmutable $date,
        public ModePaiement $mode,
        public ?int $compteBancaireId,
        public int $exercice,
    ) {}
}
