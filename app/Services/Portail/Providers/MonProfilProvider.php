<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class MonProfilProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        return new PortailSectionDTO(
            id: 'mon-profil',
            label: 'Mon profil',
            routeName: 'portail.mon-profil',
            icon: 'bi-person',
            ordre: 20,
            groupe: 'Espace personnel',
            visible: true,
            badge: null,
        );
    }
}
