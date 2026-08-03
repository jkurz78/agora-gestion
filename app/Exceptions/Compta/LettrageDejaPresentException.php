<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LettrageDejaPresentException extends \DomainException
{
    public static function forLigne(int $ligneId, string $code): self
    {
        return new self(
            "La ligne #{$ligneId} porte déjà le lettrage_code «{$code}». Délettrez-la d'abord."
        );
    }
}
