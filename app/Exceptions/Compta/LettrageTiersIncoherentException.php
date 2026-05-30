<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LettrageTiersIncoherentException extends \DomainException
{
    public static function detected(): self
    {
        return new self(
            'Toutes les lignes d\'un lettrage doivent partager le même tiers : '.
            'lettrer des lignes de tiers différents corromprait les comptes auxiliaires.'
        );
    }
}
