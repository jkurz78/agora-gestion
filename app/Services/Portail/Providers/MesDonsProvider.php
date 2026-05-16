<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Services\Tiers\TiersDonsTimelineService;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class MesDonsProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! app(TiersDonsTimelineService::class)->tiersAUnDon($tiers)) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'mes-dons',
            label: 'Mes dons',
            routeName: 'portail.mes-dons',
            icon: 'bi-gift',
            ordre: 70,
            groupe: 'Ma vie de membre',
            visible: true,
            badge: null,
        );
    }
}
