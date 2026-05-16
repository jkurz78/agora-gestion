<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class FacturesPartenairesProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! $tiers->pour_depenses) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'factures-partenaires',
            label: 'Factures',
            routeName: 'portail.factures.index',
            icon: 'bi-file-earmark-text',
            ordre: 95,
            groupe: 'Mes frais & factures',
            visible: true,
            badge: null,
        );
    }
}
