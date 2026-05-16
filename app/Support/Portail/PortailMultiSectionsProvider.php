<?php

declare(strict_types=1);

namespace App\Support\Portail;

use App\Models\Tiers;

interface PortailMultiSectionsProvider
{
    /** @return iterable<PortailSectionDTO> */
    public function resolveAll(Tiers $tiers): iterable;
}
