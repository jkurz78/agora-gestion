<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Services\Tiers\TiersCommunicationsTimelineService;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class MesMessagesProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! app(TiersCommunicationsTimelineService::class)->tiersAUnMessage($tiers)) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'mes-messages',
            label: 'Mes messages',
            routeName: 'portail.mes-messages',
            icon: 'bi-envelope',
            ordre: 90,
            groupe: 'Mes messages',
            visible: true,
            badge: null,
        );
    }
}
