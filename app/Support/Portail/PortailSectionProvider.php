<?php

declare(strict_types=1);

namespace App\Support\Portail;

use App\Models\Tiers;

interface PortailSectionProvider
{
    public function resolve(Tiers $tiers): ?PortailSectionDTO;
}
