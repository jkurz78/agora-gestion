<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class TenantBoundaryException extends \RuntimeException
{
    public static function crossTenantLigne(int $ligneId, int $ligneAssociationId, int $currentTenantId): self
    {
        return new self(
            "La ligne #{$ligneId} appartient au tenant #{$ligneAssociationId} mais le contexte courant est le tenant #{$currentTenantId}."
        );
    }
}
