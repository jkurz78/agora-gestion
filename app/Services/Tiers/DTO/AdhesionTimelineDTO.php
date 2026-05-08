<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class AdhesionTimelineDTO
{
    /**
     * @param  array<int, AdhesionLigneDTO>  $lignes
     */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount,
    ) {}
}
