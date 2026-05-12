<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CommunicationsTimelineDTO
{
    public function __construct(
        public LengthAwarePaginator $emails,
        public int $total,
        /** @var array<string, int> */
        public array $compteursParCategorie,
    ) {}
}
