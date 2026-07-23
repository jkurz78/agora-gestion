<?php

declare(strict_types=1);

namespace App\DTOs\Compta;

use App\Enums\ModePaiement;
use Carbon\CarbonImmutable;

final readonly class ReglementPosteTiers
{
    public function __construct(
        public int $transactionId,
        public int $montantCentimes,
        public CarbonImmutable $date,
        public ModePaiement $mode,
        public bool $annulable,
    ) {}
}
