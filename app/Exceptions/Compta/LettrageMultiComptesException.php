<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LettrageMultiComptesException extends \DomainException
{
    public static function detected(): self
    {
        return new self(
            'Toutes les lignes d\'un lettrage doivent partager le même compte_id.'
        );
    }
}
