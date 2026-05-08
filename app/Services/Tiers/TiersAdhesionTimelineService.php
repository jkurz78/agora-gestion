<?php

declare(strict_types=1);

namespace App\Services\Tiers;

use App\Models\Adhesion;
use App\Models\Tiers;
use App\Services\Tiers\DTO\AdhesionLigneDTO;
use App\Services\Tiers\DTO\AdhesionTimelineDTO;

final class TiersAdhesionTimelineService
{
    public function forTiers(Tiers $tiers): AdhesionTimelineDTO
    {
        $adhesions = $tiers->adhesions()
            ->with(['transaction.compte'])
            ->orderByDesc('exercice')
            ->orderByDesc('id')
            ->get();

        $lignes = $adhesions->map(fn (Adhesion $a) => new AdhesionLigneDTO($a))->all();

        return new AdhesionTimelineDTO(
            lignes: $lignes,
            totalCount: count($lignes),
        );
    }
}
