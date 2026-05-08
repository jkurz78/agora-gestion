<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class AnneeCivileDTO
{
    /**
     * @param  array<int, DonLigneDTO>  $lignes
     */
    public function __construct(
        public readonly int $annee,
        public readonly int $count,
        public readonly string $total,
        public readonly array $lignes,
    ) {}
}
