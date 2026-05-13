<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class SuitTimelineDTO
{
    /**
     * @param  array<int, SuitLigneDTO>  $lignes
     */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount, // nb tiers DISTINCTS (≠ nb lignes)
    ) {}
}
