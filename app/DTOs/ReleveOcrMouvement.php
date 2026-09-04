<?php

declare(strict_types=1);

namespace App\DTOs;

final class ReleveOcrMouvement
{
    public function __construct(
        public readonly ?string $date,
        public readonly ?string $libelle,
        public readonly float $montant,
    ) {}
}
