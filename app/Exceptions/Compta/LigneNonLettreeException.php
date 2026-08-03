<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LigneNonLettreeException extends \DomainException
{
    public static function forLigne(int $ligneId): self
    {
        return new self(
            "La ligne #{$ligneId} ne porte aucun lettrage_code — impossible de délettrer."
        );
    }
}
