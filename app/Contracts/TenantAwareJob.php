<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Tout job queued qui touche des données tenantées DOIT implémenter ce contrat
 * et utiliser le trait WithTenantContext pour garantir l'isolation.
 */
interface TenantAwareJob
{
    public function associationId(): int;
}
