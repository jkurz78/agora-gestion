<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LettrageInexistantException extends \DomainException
{
    public static function forCode(string $code): self
    {
        return new self(
            "Aucune ligne lettrée avec le code «{$code}» n'a été trouvée pour le tenant courant."
        );
    }
}
