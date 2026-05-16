<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class HistoriqueDepensesProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! $tiers->pour_depenses) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'historique-depenses',
            label: 'Historique dépenses',
            routeName: 'portail.historique.index',
            icon: 'bi-clock-history',
            ordre: 99,
            groupe: 'Mes frais & factures',
            visible: true,
            badge: null,
        );
    }
}
