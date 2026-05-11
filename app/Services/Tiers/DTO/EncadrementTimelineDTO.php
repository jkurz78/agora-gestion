<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class EncadrementTimelineDTO
{
    /** @param array<int, EncadrementLigneDTO> $lignes */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount,
    ) {}
}
