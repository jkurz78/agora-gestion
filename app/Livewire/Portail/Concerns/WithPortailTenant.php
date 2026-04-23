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
        // Why: in mono mode the route has no {association} segment. If the parameter
        // hasn't been injected by MonoAssociationResolver, Livewire resolves $association
        // via the container and produces an empty Association. Booting TenantContext with
        // an empty model would corrupt downstream queries (scope fail-closed) and crash the
        // portail layout on route('portail.logo') with a null slug.
        if ($asso instanceof Association && $asso->exists) {
            TenantContext::boot($asso);
        }
    }
}
