<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class AReferreTimelineDTO
{
    /**
     * @param  array<int, AReferreLigneDTO>  $lignes
     */
    public function __construct(
        public readonly array $lignes,
        public readonly int $totalCount, // nb tiers DISTINCTS (≠ nb lignes)
    ) {}
}
