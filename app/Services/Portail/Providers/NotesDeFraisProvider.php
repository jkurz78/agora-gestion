<?php

declare(strict_types=1);

namespace App\Services\Portail\Providers;

use App\Models\NoteDeFrais;
use App\Models\Tiers;
use App\Support\Portail\PortailSectionDTO;
use App\Support\Portail\PortailSectionProvider;

final class NotesDeFraisProvider implements PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO
    {
        if (! $tiers->pour_depenses && ! NoteDeFrais::query()->where('tiers_id', $tiers->id)->exists()) {
            return null;
        }

        return new PortailSectionDTO(
            id: 'notes-de-frais',
            label: 'Notes de frais',
            routeName: 'portail.ndf.index',
            icon: 'bi-receipt',
            ordre: 30,
            groupe: 'Mes frais & factures',
            visible: true,
            badge: null,
        );
    }
}
