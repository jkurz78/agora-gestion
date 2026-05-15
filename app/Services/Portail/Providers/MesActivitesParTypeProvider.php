<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\Tiers;
use App\Models\TypeOperation;
use App\Support\Portail\PortailMultiSectionsProvider;
use App\Support\Portail\PortailSectionDTO;

final class MesActivitesParTypeProvider implements PortailMultiSectionsProvider
{
    public function resolveAll(Tiers $tiers): iterable
    {
        $types = TypeOperation::query()
            ->whereHas('operations.participants', fn ($q) => $q->where('tiers_id', (int) $tiers->id))
            ->orderBy('nom')
            ->get();

        $ordre = 80;
        foreach ($types as $type) {
            yield new PortailSectionDTO(
                id: 'mes-activites-'.(int) $type->id,
                label: 'Mes '.mb_strtolower($type->nom),
                routeName: 'portail.mes-activites.show',
                icon: 'bi-calendar-event',
                ordre: $ordre,
                groupe: 'Mes activités',
                visible: true,
                badge: null,
                routeParams: ['typeOperation' => (int) $type->id],
            );
            $ordre += 1;
        }
    }
}
