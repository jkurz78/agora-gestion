<?php

declare(strict_types=1);

namespace App\Exceptions\Compta;

/**
 * Levée quand une transaction générée par EcritureGenerator ne respecte pas
 * l'invariant ∑ débits = ∑ crédits.
 */
final class EcritureNonEquilibreeException extends \DomainException
{
    public static function withSolde(string $debit, string $credit): self
    {
        return new self(
            "L'écriture n'est pas équilibrée : ∑ débit = {$debit}, ∑ crédit = {$credit}."
        );
    }
}
