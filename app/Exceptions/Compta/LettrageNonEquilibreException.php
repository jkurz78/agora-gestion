<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

final class LettrageNonEquilibreException extends \DomainException
{
    public static function withSolde(string $solde): self
    {
        return new self(
            "Le lettrage n'est pas équilibré : ∑ (débit − crédit) = {$solde} ≠ 0."
        );
    }
}
