<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Adhesion;
use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class MesAdhesionsProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! Adhesion::query()->where('tiers_id', $tiers->id)->exists()) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'mes-adhesions',
            label: 'Mes adhésions',
            routeName: 'portail.mes-adhesions',
            icon: 'bi-card-checklist',
            ordre: 60,
            groupe: 'Ma vie de membre',
            visible: true,
            badge: null,
        );
    }
}
