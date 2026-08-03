<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class CompteNonLettrableException extends \DomainException
{
    public static function forCompte(int $compteId, string $numeroPcg): self
    {
        return new self(
            "Le compte #{$compteId} ({$numeroPcg}) n'est pas lettrable (lettrable = FALSE)."
        );
    }
}
