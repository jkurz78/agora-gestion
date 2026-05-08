<?php

declare(strict_types=1);

namespace App\Services\Tiers\DTO;

final class DonsTimelineDTO
{
    /**
     * @param  array<int, AnneeCivileDTO>  $annees  Clé = année civile (ex 2025), ordre desc
     */
    public function __construct(
        public readonly array $annees,
        public readonly int $totalCount,
        public readonly string $totalMontant,
        public readonly ?string $raisonBlocageGlobal,
    ) {}
}
