<?php

declare(strict_types=1);

namespace App\Exceptions\Immobilisation;

use RuntimeException;

final class DotationInterditeException extends RuntimeException
{
    public static function exerciceNonCommence(int $exercice): self
    {
        return new self(
            "L'exercice {$exercice} n'a pas encore commencé : ses dotations ne peuvent pas être générées."
        );
    }

    public static function exerciceCloture(int $annee): self
    {
        return new self(
            "L'exercice {$annee} est clôturé : ses dotations ne peuvent plus être "
            .'générées, recalculées ni annulées.'
        );
    }
}
