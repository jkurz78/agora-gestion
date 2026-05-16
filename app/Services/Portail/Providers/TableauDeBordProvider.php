<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class TableauDeBordProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        return new PortailSectionDTO(
            id: 'tableau-de-bord',
            label: 'Tableau de bord',
            routeName: 'portail.home',
            icon: 'bi-house-door',
            ordre: 10,
            groupe: 'Espace personnel',
            visible: true,
            badge: null,
        );
    }
}
