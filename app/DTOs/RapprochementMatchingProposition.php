<?php

declare(strict_types=1);

namespace App\DTOs;

final class RapprochementMatchingProposition
{
    public function __construct(
        public readonly ?string $mouvement_date,
        public readonly ?string $mouvement_libelle,
        public readonly float $mouvement_montant,
        public readonly int $transaction_id,
        public readonly string $transaction_type,
        public readonly int $score,
    ) {}
}
