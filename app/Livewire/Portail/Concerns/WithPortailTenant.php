<?php

declare(strict_types=1);

namespace App\Livewire\Portail\Concerns;

use App\Models\Association;
use App\Tenant\TenantContext;

trait WithPortailTenant
{
    public function bootedWithPortailTenant(): void
    {
        /** @var Association|null $asso */
        $asso = $this->association ?? null;
        if ($asso instanceof Association) {
            TenantContext::boot($asso);
        }
    }
}
