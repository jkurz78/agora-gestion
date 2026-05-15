<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Participant;
use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class MesActivitesProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! Participant::query()->where('tiers_id', (int) $tiers->id)->exists()) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'mes-activites',
            label: 'Mes activités',
            routeName: 'portail.mes-activites',
            icon: 'bi-calendar-event',
            ordre: 80,
            groupe: 'Mes activités',
            visible: true,
            badge: null,
        );
    }
}
